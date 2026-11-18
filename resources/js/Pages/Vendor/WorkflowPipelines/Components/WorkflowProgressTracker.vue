<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ cycle: number; currentStepId: number | string | null; steps: Array<{ id: number | string; sequence: number; name: string; status?: string; cycle?: number }> }>();
const currentCycleSteps = computed(() => props.steps
    .filter((step) => step.cycle === undefined || step.cycle === props.cycle)
    .sort((a, b) => a.sequence - b.sequence));
</script>

<template>
    <section class="space-y-3" aria-labelledby="progress-title"><div class="flex flex-wrap items-center justify-between gap-2"><h2 id="progress-title" class="font-semibold">Approval progress</h2><span class="rounded-full bg-secondary px-2 py-1 text-xs">Cycle {{ cycle }}</span></div><ol class="space-y-2"><li v-for="step in currentCycleSteps" :key="step.id" :class="['flex min-w-0 items-start gap-3 rounded-lg border p-3', String(step.id) === String(currentStepId) ? 'border-primary bg-primary/5' : 'border-border bg-card']"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-muted text-sm">{{ step.sequence }}</span><span class="min-w-0 flex-1"><span class="block break-words font-medium">{{ step.name }}</span><span class="text-sm text-muted-foreground">{{ step.status ?? 'Waiting' }}</span></span></li></ol></section>
</template>
