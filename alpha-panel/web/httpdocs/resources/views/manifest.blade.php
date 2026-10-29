{
    "$schema": "https://json.schemastore.org/web-manifest-combined.json",
    "name": "AlphaPanel",
    "description": "AlphaPanel is a web-based control panel for the vhost management.",
    "short_name": "AlphaPanel",
    "display": "standalone",
    "scope": "/",
    "theme_color": "#061D47",
    "background_color": "#061D47",
    "start_url": "/",
    "manifest_version": 2,
    "version": "1.3.0",
    "shortcuts": [
        {
            "name": "AlphaPanel",
            "short_name": "AlphaPanel",
            "description": "AlphaPanel is a web-based control panel for the vhost management.",
            "url": "{{config('app.url')}}",
            "icons": [{ "src": "{{config('app.url')}}/img/android-icon-192x192.png", "sizes": "192x192", "type": "image/png" }]
        }
    ],
    "icons": [
        {
            "src": "{{config('app.url')}}/img/AlphaPanel-logo.svg",
            "sizes": "any",
            "type": "image/svg+xml",
            "purpose": "any"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-36x36.png",
            "sizes": "36x36",
            "type": "image/png"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-48x48.png",
            "sizes": "48x48",
            "type": "image/png"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-72x72.png",
            "sizes": "72x72",
            "type": "image/png"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-96x96.png",
            "sizes": "96x96",
            "type": "image/png"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-144x144.png",
            "sizes": "144x144",
            "type": "image/png"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-192x192.png",
            "sizes": "192x192",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-512x512.png",
            "sizes": "512x512",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "{{config('app.url')}}/img/maskable-icon-192x192.png",
            "sizes": "192x192",
            "type": "image/png",
            "purpose": "maskable"
        },
        {
            "src": "{{config('app.url')}}/img/maskable-icon-512x512.png",
            "sizes": "512x512",
            "type": "image/png",
            "purpose": "maskable"
        }
    ]
}
