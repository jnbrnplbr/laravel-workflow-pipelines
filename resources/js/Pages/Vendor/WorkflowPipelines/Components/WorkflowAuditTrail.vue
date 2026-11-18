<script setup lang="ts">
defineProps<{ entries: Array<{ id: number | string; action: string; actor?: { name?: string } | string | null; remarks?: string | null; created_at: string }> }>();
function actorName(actor: { name?: string } | string | null | undefined): string { return typeof actor === 'string' ? actor : actor?.name ?? 'System'; }
</script>

<template>
    <section class="space-y-3" aria-labelledby="audit-title"><h2 id="audit-title" class="font-semibold">Workflow history</h2><ol v-if="entries.length" class="space-y-3"><li v-for="entry in entries" :key="entry.id" class="min-w-0 border-l-2 border-border pl-4"><div class="flex flex-wrap items-baseline justify-between gap-x-3"><p class="font-medium">{{ entry.action }} <span class="font-normal text-muted-foreground">by {{ actorName(entry.actor) }}</span></p><time class="text-xs text-muted-foreground">{{ entry.created_at }}</time></div><p v-if="entry.remarks" class="mt-1 break-words text-sm text-muted-foreground">{{ entry.remarks }}</p></li></ol><p v-else class="text-sm text-muted-foreground">No workflow actions have been recorded.</p></section>
</template>
