{
    "$schema": "https://json.schemastore.org/web-manifest-combined.json",
    "name": "AlphaPanel",
    "description": "AlphaPanel is a web-based control panel for the vhost management.",
    "short_name": "AlphaPanel",
    "display": "standalone",
    "scope": "/",
    "theme_color": "#293045",
    "background_color": "#293045",
    "start_url": "/",
    "manifest_version": 2,
    "version": "1.0.9",
    "shortcuts": [
        {
            "name": "AlphaPanel",
            "short_name": "AlphaPanel",
            "description": "AlphaPanel is a web-based control panel for the vhost management.",
            "url": "{{config('app.url')}}",
            "icons": [{ "src": "{{config('app.url')}}/img/android-icon-192x192.png", "sizes": "192x192" }]
        }
    ],
    "icons": [
        {
            "src": "{{config('app.url')}}/img/android-icon-36x36.png",
            "sizes": "36x36",
            "type": "image/svg+xml",
            "density": "0.75"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-72x72.png",
            "sizes": "72x72",
            "type": "image/svg+xml",
            "density": "1.5"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-96x96.png",
            "sizes": "96x96",
            "type": "image/svg+xml",
            "density": "2.0"
        },
        {
            "src": "{{config('app.url')}}/img/android-icon-192x192.png",
            "sizes": "192x192",
            "type": "image/svg+xml",
            "density": "4.0"
        }
    ]
}
