<script setup lang="ts">
import { onMounted, ref } from 'vue';

const mounted = ref(false);
onMounted(() => { mounted.value = true; });
</script>

<template>
    <Teleport v-if="mounted" to="body"><slot /></Teleport>
</template>
