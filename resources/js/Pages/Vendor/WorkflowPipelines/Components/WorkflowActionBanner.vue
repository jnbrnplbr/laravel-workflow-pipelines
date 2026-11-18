<script setup lang="ts">
import { ref } from 'vue';
import BodyTeleport from './BodyTeleport.vue';

type Action = { value: string; label: string; targets?: Array<{ id: number | string; label: string }>; fields?: string[] };
const props = defineProps<{ status: string; actions: Action[]; canAct: boolean; processing?: boolean }>();
const emit = defineEmits<{ submit: [action: string, data: Record<string, unknown>] }>();
const selected = ref<Action | null>(null);
const form = ref({ target_step_id: '', fields: [] as string[], remarks: '' });
function begin(action: Action) { selected.value = action; form.value = { target_step_id: '', fields: [], remarks: '' }; }
function submit() { if (!selected.value) return; emit('submit', selected.value.value, { ...form.value }); selected.value = null; }
</script>

<template>
    <section v-if="canAct" class="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground sm:flex-row sm:items-center sm:justify-between" aria-label="Workflow actions">
        <div><p class="font-medium">Approval required</p><p class="text-sm text-muted-foreground">Current status: {{ status }}</p></div>
        <div class="flex flex-wrap gap-2"><ActionButton v-for="action in actions" :key="action.value" type="button" variant="outline" @click="begin(action)">{{ action.label }}</ActionButton></div>
        <BodyTeleport><div v-if="selected" class="fixed inset-0 z-50 flex items-end justify-center bg-foreground/50 p-0 sm:items-center sm:p-4" @click.self="selected = null"><section role="dialog" aria-modal="true" aria-labelledby="action-title" class="w-full max-w-lg space-y-4 rounded-t-xl border border-border bg-card p-5 text-card-foreground sm:rounded-xl"><h2 id="action-title" class="text-lg font-semibold">{{ selected.label }}</h2><label v-if="selected.targets?.length" class="grid gap-1 text-sm">Target step<select v-model="form.target_step_id" class="min-h-10 rounded-md border border-input bg-background px-3"><option value="">Choose a step</option><option v-for="target in selected.targets" :key="target.id" :value="target.id">{{ target.label }}</option></select></label><fieldset v-if="selected.fields?.length" class="space-y-2"><legend class="text-sm font-medium">Fields to unlock</legend><label v-for="field in selected.fields" :key="field" class="flex items-center gap-2 text-sm"><input v-model="form.fields" type="checkbox" :value="field">{{ field }}</label></fieldset><label class="grid gap-1 text-sm">Remarks<textarea v-model="form.remarks" rows="3" class="rounded-md border border-input bg-background p-3" /></label><div class="flex justify-end gap-2"><ActionButton type="button" variant="outline" @click="selected = null">Cancel</ActionButton><ActionButton type="button" :disabled="processing" @click="submit">Confirm</ActionButton></div></section></div></BodyTeleport>
    </section>
</template>
