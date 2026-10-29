<template>
    <div>
        <dt class="ap-eyebrow">
            {{ label }}
        </dt>
        <dd class="mt-1 text-sm text-fg">
            {{ value }}
        </dd>
    </div>
</template>

<script setup lang="ts">
defineProps<{
    label: string;
    value: string;
}>();
</script>
