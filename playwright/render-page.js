/**
 * Playwright Page Renderer Fallback
 *
 * Runs headless browser to render JavaScript SPA applications,
 * capturing rendered DOM and text-based network resources (JS, CSS, JSON).
 *
 * Usage:
 *   node playwright/render-page.js '{"url":"https://example.com","timeout":30000}'
 *
 * Input: JSON via argv[2] or stdin
 * Output: JSON via stdout
 */

const fs = require('fs');

async function main() {
    let inputRaw = process.argv[2];

    if (!inputRaw) {
        try {
            inputRaw = fs.readFileSync(0, 'utf-8');
        } catch (e) {
            // No stdin
        }
    }

    if (!inputRaw) {
        console.log(JSON.stringify({
            success: false,
            error: 'No input JSON provided'
        }));
        process.exit(1);
    }

    let input;
    try {
        input = JSON.parse(inputRaw);
    } catch (e) {
        console.log(JSON.stringify({
            success: false,
            error: 'Invalid input JSON: ' + e.message
        }));
        process.exit(1);
    }

    const targetUrl = input.url;
    const timeoutMs = input.timeout || 45000;
    const captureResources = input.capture_resources !== false;

    if (!targetUrl) {
        console.log(JSON.stringify({
            success: false,
            error: 'Missing url in input JSON'
        }));
        process.exit(1);
    }

    let chromium;
    try {
        const pw = require('playwright');
        chromium = pw.chromium;
    } catch (e) {
        console.log(JSON.stringify({
            success: false,
            error: 'Playwright module is not installed. Run "npm install" inside playwright/ directory.',
            missing_dependency: true
        }));
        process.exit(1);
    }

    let browser;
    try {
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--ignore-certificate-errors']
        });

        const context = await browser.newContext({
            ignoreHTTPSErrors: true,
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36 (Playwright/LoremChecker)'
        });

        const page = await context.newPage();
        const capturedResources = [];

        if (captureResources) {
            page.on('response', async (response) => {
                try {
                    const resUrl = response.url();
                    const status = response.status();
                    const contentType = response.headers()['content-type'] || '';

                    // Capture JS, CSS, JSON text resources
                    if (status === 200 && (
                        contentType.includes('javascript') ||
                        contentType.includes('css') ||
                        contentType.includes('json')
                    )) {
                        const body = await response.text().catch(() => '');
                        if (body && body.length < 2 * 1024 * 1024) { // Max 2MB per resource
                            let type = 'other';
                            if (contentType.includes('javascript')) type = 'javascript';
                            else if (contentType.includes('css')) type = 'css';
                            else if (contentType.includes('json')) type = 'json';

                            capturedResources.push({
                                url: resUrl,
                                type: type,
                                content: body
                            });
                        }
                    }
                } catch (err) {
                    // Ignore response reading errors
                }
            });
        }

        await page.goto(targetUrl, {
            waitUntil: 'networkidle',
            timeout: timeoutMs
        });

        const renderedHtml = await page.content();
        const finalUrl = page.url();

        await browser.close();

        console.log(JSON.stringify({
            success: true,
            requested_url: targetUrl,
            final_url: finalUrl,
            html: renderedHtml,
            resources: capturedResources,
            error: null
        }));

    } catch (err) {
        if (browser) {
            try { await browser.close(); } catch (e) {}
        }

        console.log(JSON.stringify({
            success: false,
            requested_url: targetUrl,
            error: err.message
        }));
        process.exit(1);
    }
}

main();
