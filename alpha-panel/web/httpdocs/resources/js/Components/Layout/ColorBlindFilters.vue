<template>
    <svg
        aria-hidden="true"
        focusable="false"
        width="0"
        height="0"
        style="position: absolute; width: 0; height: 0; overflow: hidden;"
    >
        <defs>
            <filter id="cb-protanopia" color-interpolation-filters="linearRGB">
                <feColorMatrix
                    type="matrix"
                    values="0 0 0 0 0 0.7 1 0 0 0 0.7 0 1 0 0 0 0 0 1 0"
                />
            </filter>
            <filter id="cb-deuteranopia" color-interpolation-filters="linearRGB">
                <feColorMatrix
                    type="matrix"
                    values="1 0.7 0 0 0 0 0 0 0 0 0 0.7 1 0 0 0 0 0 1 0"
                />
            </filter>
            <filter id="cb-tritanopia" color-interpolation-filters="linearRGB">
                <feColorMatrix
                    type="matrix"
                    values="1 0 0.7 0 0 0 1 0.7 0 0 0 0 0 0 0 0 0 0 1 0"
                />
            </filter>
        </defs>
    </svg>
</template>

<script setup lang="ts"></script>
