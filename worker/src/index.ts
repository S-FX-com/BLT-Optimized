/**
 * Blt Image Optimizer — Cloudflare Worker
 *
 * Hosted on the S-FX Cloudflare account. One Worker serves all client sites.
 * Accepts an image URL, applies cf.image transforms (WebP conversion,
 * quality, max-width), and streams the optimized binary back.
 *
 * IMPORTANT: cf.image transformations only work when this Worker is deployed
 * to a Cloudflare *zone route* (e.g. img-optimizer.s-fx.com/optimize) with
 * Image Transformations enabled. They are NOT available on *.workers.dev.
 */

export interface Env {
	/** Shared bearer secret. Set via `wrangler secret put WORKER_SECRET`. */
	WORKER_SECRET: string;
}

/** Normalized request after validation — all fields populated. */
interface OptimizeRequest {
	image_url: string;
	quality: number;
	format: AllowedFormat;
	max_width: number;
}

interface ErrorBody {
	error: string;
}

const ALLOWED_FORMATS = ['webp', 'avif'] as const;
type AllowedFormat = (typeof ALLOWED_FORMATS)[number];

/**
 * Build a JSON error response.
 */
function jsonError(message: string, status: number): Response {
	const body: ErrorBody = { error: message };
	return new Response(JSON.stringify(body), {
		status,
		headers: { 'Content-Type': 'application/json' },
	});
}

/**
 * Constant-time-ish comparison to validate the bearer token.
 */
function isAuthorized(request: Request, env: Env): boolean {
	const header = request.headers.get('Authorization') || '';
	const match = header.match(/^Bearer\s+(.+)$/i);

	if (!match) {
		return false;
	}

	const provided = match[1];
	const expected = env.WORKER_SECRET || '';

	if (!expected || provided.length !== expected.length) {
		return false;
	}

	// Length-safe comparison.
	let diff = 0;
	for (let i = 0; i < expected.length; i++) {
		diff |= provided.charCodeAt(i) ^ expected.charCodeAt(i);
	}
	return diff === 0;
}

/**
 * Detect whether cf.image transforms are available in this deployment.
 *
 * On workers.dev the `cf` request property does not honor `image` options,
 * so we attempt a trivial transform on a 1x1 data fetch and inspect headers.
 * In practice the most reliable signal is the absence of zone context, so we
 * report based on whether the request reached a real zone (cf object present).
 */
async function cfImageAvailable(request: Request): Promise<boolean> {
	// `request.cf` is populated on zone-routed requests, absent on workers.dev.
	// This is the simplest reliable heuristic without burning a transform.
	const cf = (request as unknown as { cf?: unknown }).cf;
	return typeof cf === 'object' && cf !== null;
}

/**
 * Handle GET /health — used by the WordPress plugin's "Test Connection".
 */
async function handleHealth(request: Request, env: Env): Promise<Response> {
	if (!isAuthorized(request, env)) {
		return jsonError('Unauthorized.', 401);
	}

	const available = await cfImageAvailable(request);

	return new Response(
		JSON.stringify({
			ok: true,
			cf_image: available,
			worker: 'blt-image-optimizer',
		}),
		{ headers: { 'Content-Type': 'application/json' } }
	);
}

/**
 * Validate and normalize the optimize request body.
 */
function parseOptimizeBody(raw: unknown): OptimizeRequest | null {
	if (typeof raw !== 'object' || raw === null) {
		return null;
	}

	const body = raw as Record<string, unknown>;

	if (typeof body.image_url !== 'string' || body.image_url.length === 0) {
		return null;
	}

	let url: URL;
	try {
		url = new URL(body.image_url);
	} catch {
		return null;
	}

	if (url.protocol !== 'https:' && url.protocol !== 'http:') {
		return null;
	}

	const quality =
		typeof body.quality === 'number' && body.quality >= 1 && body.quality <= 100
			? Math.round(body.quality)
			: 82;

	const format: AllowedFormat = ALLOWED_FORMATS.includes(body.format as AllowedFormat)
		? (body.format as AllowedFormat)
		: 'webp';

	const max_width =
		typeof body.max_width === 'number' && body.max_width > 0
			? Math.round(body.max_width)
			: 0;

	return { image_url: url.toString(), quality, format, max_width };
}

/**
 * Handle POST /optimize.
 */
async function handleOptimize(request: Request, env: Env): Promise<Response> {
	if (!isAuthorized(request, env)) {
		return jsonError('Unauthorized.', 401);
	}

	let raw: unknown;
	try {
		raw = await request.json();
	} catch {
		return jsonError('Invalid JSON body.', 400);
	}

	const params = parseOptimizeBody(raw);
	if (!params) {
		return jsonError('Missing or invalid image_url / parameters.', 400);
	}

	const imageOptions: RequestInitCfPropertiesImage = {
		format: params.format,
		quality: params.quality,
		fit: 'scale-down',
	};

	if (params.max_width > 0) {
		imageOptions.width = params.max_width;
	}

	let upstream: Response;
	try {
		upstream = await fetch(params.image_url, {
			cf: { image: imageOptions },
			headers: { Accept: `image/${params.format}` },
		});
	} catch (err) {
		const detail = err instanceof Error ? err.message : 'unknown error';
		return jsonError(`Failed to fetch or transform image: ${detail}`, 422);
	}

	if (!upstream.ok) {
		return jsonError(
			`Upstream returned HTTP ${upstream.status} when fetching the image.`,
			422
		);
	}

	const contentType = upstream.headers.get('Content-Type') || '';
	if (!contentType.startsWith('image/')) {
		return jsonError(
			'Transform did not return an image. Ensure the Worker runs on a Cloudflare zone with Image Transformations enabled (not workers.dev).',
			422
		);
	}

	const headers = new Headers();
	headers.set('Content-Type', `image/${params.format}`);
	headers.set('Cache-Control', 'no-store');
	headers.set('X-Blt-Optimizer', 'cf-image');

	return new Response(upstream.body, { status: 200, headers });
}

export default {
	async fetch(request: Request, env: Env): Promise<Response> {
		const url = new URL(request.url);

		// CORS / preflight is unnecessary (server-to-server), but be explicit.
		if (request.method === 'GET' && url.pathname.endsWith('/health')) {
			return handleHealth(request, env);
		}

		if (request.method === 'POST' && url.pathname.endsWith('/optimize')) {
			return handleOptimize(request, env);
		}

		if (request.method !== 'POST' && request.method !== 'GET') {
			return jsonError('Method not allowed.', 405);
		}

		return jsonError('Not found. Use POST /optimize or GET /health.', 404);
	},
};
