/**
 * Media tools — upload to the WordPress media library.
 */

import { createHash } from 'node:crypto';
import type { WordPressBlockClient } from '../client.js';
import type { UploadMediaRequest } from '../types.js';

/** Recommended raw-byte size per chunk (~2–3 KB keeps LLM tool args reliable). */
const CHUNK_RAW_BYTES = 2400;

export const MEDIA_TOOLS = [
  {
    name: 'upload_media',
    description:
      'Upload an item to the WordPress media library. Prefer `path` (local file on the MCP host, multipart) or `url` (server sideload). Use `data_base64` only for tiny files — larger single-shot base64 is often truncated by LLM tool calls (invalid_base64). For generated images without a public URL when `path` is unavailable, use upload_media_begin → upload_media_chunk → upload_media_finish. Exactly one of path/url/data_base64.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true, title: 'Upload media' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        path: {
          type: 'string',
          description: 'Absolute path on the MCP host. Will be read and POSTed as multipart.',
        },
        url: {
          type: 'string',
          description: 'Public URL the WordPress site can fetch.',
        },
        data_base64: {
          type: 'string',
          description: 'Base64-encoded file contents for tiny files only (requires filename).',
        },
        filename: {
          type: 'string',
          description: 'Override filename (required when using data_base64).',
        },
        title: { type: 'string' },
        alt_text: {
          type: 'string',
          description: 'Saved as _wp_attachment_image_alt meta. Critical for accessibility.',
        },
        caption: { type: 'string' },
        description: { type: 'string' },
        post_id: {
          type: 'number',
          description: 'Attach to a parent post (sets post_parent).',
        },
      },
    },
  },
  {
    name: 'upload_media_begin',
    description:
      'Start a chunked media upload. Pass filename + content_md5 (hex MD5 of raw file bytes). Then upload_media_chunk for each piece, then upload_media_finish.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false, title: 'Begin chunked media upload' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        filename: { type: 'string' },
        content_md5: { type: 'string', description: '32-char hex MD5 of the complete raw file.' },
        byte_size: { type: 'number' },
        title: { type: 'string' },
        alt_text: { type: 'string' },
        caption: { type: 'string' },
        description: { type: 'string' },
        post_id: { type: 'number' },
      },
      required: ['filename', 'content_md5'],
    },
  },
  {
    name: 'upload_media_chunk',
    description:
      'Append one chunk (base64 of ~2–3KB raw bytes). Indexes must be contiguous from 0.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false, title: 'Append media upload chunk' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        upload_id: { type: 'string' },
        index: { type: 'number' },
        data_base64: { type: 'string' },
      },
      required: ['upload_id', 'index', 'data_base64'],
    },
  },
  {
    name: 'upload_media_finish',
    description: 'Assemble chunks, verify content_md5, create the media library attachment.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false, title: 'Finish chunked media upload' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        upload_id: { type: 'string' },
      },
      required: ['upload_id'],
    },
  },
  {
    name: 'upload_media_abort',
    description: 'Abort a chunked upload session and delete temp chunks.',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false, title: 'Abort chunked media upload' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        upload_id: { type: 'string' },
      },
      required: ['upload_id'],
    },
  },
  {
    name: 'upload_media_from_path_chunked',
    description:
      'Convenience: read a local file on the MCP host, MD5 it, and upload via the chunked REST API (begin/chunk/finish). Prefer upload_media with path (multipart) when available; use this when exercising the chunked path.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false, title: 'Chunked upload from local path' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        path: { type: 'string' },
        filename: { type: 'string' },
        title: { type: 'string' },
        alt_text: { type: 'string' },
        caption: { type: 'string' },
        description: { type: 'string' },
        post_id: { type: 'number' },
      },
      required: ['path'],
    },
  },
];

export async function handleMediaTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient,
): Promise<unknown> {
  switch (toolName) {
    case 'upload_media': {
      const modes = (['path', 'url', 'data_base64'] as const).filter(
        (k) => typeof args[k] === 'string' && (args[k] as string).length > 0,
      );
      if (modes.length === 0) {
        throw new Error('upload_media: provide one of "path", "url", or "data_base64"');
      }
      if (modes.length > 1) {
        throw new Error(
          `upload_media: only one of path/url/data_base64 may be supplied (got ${modes.join(', ')})`,
        );
      }
      if (args.data_base64 && !args.filename) {
        throw new Error('upload_media: "filename" is required when using data_base64');
      }
      return client.uploadMedia(args as UploadMediaRequest);
    }
    case 'upload_media_begin':
      return client.beginChunkedMediaUpload(args as Record<string, unknown>);
    case 'upload_media_chunk':
      return client.appendChunkedMediaUpload(args as Record<string, unknown>);
    case 'upload_media_finish':
      return client.finishChunkedMediaUpload(String(args.upload_id ?? ''));
    case 'upload_media_abort':
      return client.abortChunkedMediaUpload(String(args.upload_id ?? ''));
    case 'upload_media_from_path_chunked': {
      if (typeof args.path !== 'string' || !args.path) {
        throw new Error('upload_media_from_path_chunked: path is required');
      }
      const fs = await import('node:fs/promises');
      const nodePath = await import('node:path');
      const data = await fs.readFile(args.path);
      const filename =
        typeof args.filename === 'string' && args.filename
          ? args.filename
          : nodePath.basename(args.path);
      const content_md5 = createHash('md5').update(data).digest('hex');
      const begin = await client.beginChunkedMediaUpload({
        filename,
        content_md5,
        byte_size: data.length,
        title: args.title,
        alt_text: args.alt_text,
        caption: args.caption,
        description: args.description,
        post_id: args.post_id,
      });
      const uploadId = String((begin as { upload_id: string }).upload_id);
      let index = 0;
      for (let offset = 0; offset < data.length; offset += CHUNK_RAW_BYTES) {
        const piece = data.subarray(offset, offset + CHUNK_RAW_BYTES);
        await client.appendChunkedMediaUpload({
          upload_id: uploadId,
          index,
          data_base64: piece.toString('base64'),
        });
        index += 1;
      }
      return client.finishChunkedMediaUpload(uploadId);
    }
    default:
      throw new Error(`Unknown media tool: ${toolName}`);
  }
}
