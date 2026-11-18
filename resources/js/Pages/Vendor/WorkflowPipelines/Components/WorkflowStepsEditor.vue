<script setup lang="ts">
import { computed } from 'vue';

export interface WorkflowStepInput {
    id?: number;
    client_key: string;
    sequence: number;
    role_id: number | string;
    assignable: boolean;
    editable: boolean;
    status_id: number | string;
    rules: Array<{ action: string; label: string; target_step_ids: Array<number | string>; editable_fields: string[]; reset_approval: boolean; model_types: string[] }>;
    notifications: Array<{ channel: string; events: string[]; recipients: string[]; is_active: boolean; emails?: string[]; subject?: string | null; message?: string | null; model_types?: string[] }>;
}

const props = defineProps<{
    modelValue: WorkflowStepInput[];
    roles: Array<{ id: number; name: string }>;
    statuses: Array<{ id: number; name: string }>;
    registries?: Array<{ type: string; label: string; fields: Record<string, string> }>;
}>();
const emit = defineEmits<{ 'update:modelValue': [steps: WorkflowStepInput[]] }>();
const steps = computed(() => props.modelValue);

function update(index: number, key: keyof WorkflowStepInput, value: unknown) {
    emit('update:modelValue', steps.value.map((step, i) => i === index ? { ...step, [key]: value } : step));
}
function addStep() {
    const sequence = steps.value.length + 1;
    emit('update:modelValue', [...steps.value, {
        client_key: `new-${crypto.randomUUID()}`, sequence, role_id: '', assignable: false,
        editable: false, status_id: '', rules: [],
        notifications: [{ channel: 'database', events: ['approved', 'reverted', 'rejected'], recipients: ['next_handlers', 'creator'], is_active: true }],
    }]);
}
function removeStep(index: number) {
    emit('update:modelValue', steps.value.filter((_, i) => i !== index).map((step, i) => ({ ...step, sequence: i + 1 })));
}
function addRule(index: number) {
    const step = steps.value[index];
    update(index, 'rules', [...step.rules, { action: 'approve', label: '', target_step_ids: [], editable_fields: [], reset_approval: false, model_types: [] }]);
}
function patchRule(index: number, ruleIndex: number, key: string, value: unknown) {
    update(index, 'rules', steps.value[index].rules.map((rule, i) => i === ruleIndex ? { ...rule, [key]: value } : rule));
}
function patchNotification(index: number, key: string, value: unknown) {
    const current = steps.value[index].notifications[0] ?? { channel: 'database', events: [], recipients: [], is_active: true };
    update(index, 'notifications', [{ ...current, [key]: value }]);
}
function ruleFieldOptions(rule: WorkflowStepInput['rules'][number]): Array<{ key: string; label: string }> {
    const selected = rule.model_types.length ? props.registries?.filter((registry) => rule.model_types.includes(registry.type)) : props.registries;
    const fields = new Map<string, string>();
    for (const registry of selected ?? []) for (const [key, label] of Object.entries(registry.fields)) fields.set(key, label);
    return [...fields].map(([key, label]) => ({ key, label }));
}
</script>

<template>
    <section class="space-y-4" aria-labelledby="steps-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="steps-heading" class="text-lg font-semibold">Workflow steps</h2>
            <ActionButton type="button" variant="outline" @click="addStep">Add step</ActionButton>
        </div>
        <article v-for="(step, index) in steps" :key="step.client_key" class="space-y-4 rounded-lg border border-border bg-card p-4 text-card-foreground">
            <div class="flex items-center justify-between gap-3"><h3 class="font-medium">Step {{ index + 1 }}</h3><ActionButton type="button" variant="destructive" @click="removeStep(index)">Remove</ActionButton></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="grid gap-1 text-sm">Approver role
                    <select class="min-h-10 rounded-md border border-input bg-background px-3" :value="step.role_id" @change="update(index, 'role_id', ($event.target as HTMLSelectElement).value)"><option value="">Choose a role</option><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select>
                </label>
                <label class="grid gap-1 text-sm">Display status
                    <select class="min-h-10 rounded-md border border-input bg-background px-3" :value="step.status_id" @change="update(index, 'status_id', ($event.target as HTMLSelectElement).value)"><option value="">Choose a status</option><option v-for="status in statuses" :key="status.id" :value="status.id">{{ status.name }}</option></select>
                </label>
            </div>
            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" :checked="step.assignable" @change="update(index, 'assignable', ($event.target as HTMLInputElement).checked)"> Assign to a specific approver</label>
                <label class="flex items-center gap-2"><input type="checkbox" :checked="step.editable" @change="update(index, 'editable', ($event.target as HTMLInputElement).checked)"> Handler may edit this step</label>
            </div>
            <section class="space-y-3 border-t border-border pt-4">
                <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="text-sm font-semibold">Action rules</h4><ActionButton type="button" variant="outline" @click="addRule(index)">Add rule</ActionButton></div>
                <div v-for="(rule, ruleIndex) in step.rules" :key="ruleIndex" class="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">Action<select :value="rule.action" class="min-h-10 rounded-md border border-input bg-background px-3" @change="patchRule(index, ruleIndex, 'action', ($event.target as HTMLSelectElement).value)"><option value="approve">Approve</option><option value="reject">Reject</option><option value="revert">Revert</option></select></label>
                    <label class="grid gap-1 text-sm">Button label<input :value="rule.label" maxlength="255" class="min-h-10 rounded-md border border-input bg-background px-3" @input="patchRule(index, ruleIndex, 'label', ($event.target as HTMLInputElement).value)"></label>
                    <fieldset class="space-y-2 sm:col-span-2"><legend class="text-sm font-medium">Transaction types (all when none selected)</legend><div class="flex flex-wrap gap-3"><label v-for="registry in registries ?? []" :key="registry.type" class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="rule.model_types.includes(registry.type)" @change="patchRule(index, ruleIndex, 'model_types', ($event.target as HTMLInputElement).checked ? [...rule.model_types, registry.type] : rule.model_types.filter((type) => type !== registry.type))">{{ registry.label }}</label></div></fieldset>
                    <template v-if="rule.action === 'revert'">
                        <label class="grid gap-1 text-sm">Earlier steps<select multiple :value="rule.target_step_ids.map(String)" class="min-h-24 rounded-md border border-input bg-background p-2" @change="patchRule(index, ruleIndex, 'target_step_ids', Array.from(($event.target as HTMLSelectElement).selectedOptions, (option) => option.value))"><option v-for="target in steps.slice(0, index)" :key="target.client_key" :value="target.id ?? target.client_key">Step {{ target.sequence }} — {{ roles.find((role) => String(role.id) === String(target.role_id))?.name ?? 'Unassigned role' }}</option></select></label>
                        <fieldset class="space-y-2"><legend class="text-sm font-medium">Fields to unlock</legend><div v-for="field in ruleFieldOptions(rule)" :key="field.key" class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="rule.editable_fields.includes(field.key)" @change="patchRule(index, ruleIndex, 'editable_fields', ($event.target as HTMLInputElement).checked ? [...rule.editable_fields, field.key] : rule.editable_fields.filter((key) => key !== field.key))"><span>{{ field.label }}</span></div><p v-if="ruleFieldOptions(rule).length === 0" class="text-sm text-muted-foreground">No editable fields are registered.</p></fieldset>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="rule.reset_approval" @change="patchRule(index, ruleIndex, 'reset_approval', ($event.target as HTMLInputElement).checked)"> Reset approval at the target step</label>
                    </template>
                    <ActionButton class="sm:col-span-2 sm:justify-self-start" type="button" variant="destructive" @click="update(index, 'rules', step.rules.filter((_, i) => i !== ruleIndex))">Remove rule</ActionButton>
                </div>
                <p v-if="step.rules.length === 0" class="text-sm text-muted-foreground">No custom action rules. The workflow uses its standard actions.</p>
            </section>
            <section class="space-y-3 border-t border-border pt-4">
                <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="text-sm font-semibold">Notifications</h4><label class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="step.notifications[0]?.is_active ?? true" @change="patchNotification(index, 'is_active', ($event.target as HTMLInputElement).checked)">Enabled</label></div>
                <label class="grid max-w-sm gap-1 text-sm">Channel<select :value="step.notifications[0]?.channel ?? 'database'" class="min-h-10 rounded-md border border-input bg-background px-3" @change="patchNotification(index, 'channel', ($event.target as HTMLSelectElement).value)"><option value="database">In-app</option><option value="mail">Email</option></select></label>
                <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    <label v-for="event in ['approved', 'reverted', 'rejected']" :key="event" class="flex items-center gap-2"><input type="checkbox" :checked="step.notifications[0]?.events.includes(event) ?? true" @change="patchNotification(index, 'events', ($event.target as HTMLInputElement).checked ? [...(step.notifications[0]?.events ?? []), event] : (step.notifications[0]?.events ?? []).filter((item) => item !== event))">{{ event }}</label>
                    <label v-for="recipient in ['next_handlers', 'creator', 'participants']" :key="recipient" class="flex items-center gap-2"><input type="checkbox" :checked="step.notifications[0]?.recipients.includes(recipient) ?? ['next_handlers', 'creator'].includes(recipient)" @change="patchNotification(index, 'recipients', ($event.target as HTMLInputElement).checked ? [...(step.notifications[0]?.recipients ?? ['next_handlers', 'creator']), recipient] : (step.notifications[0]?.recipients ?? []).filter((item) => item !== recipient))">{{ recipient.replace('_', ' ') }}</label>
                </div>
                <template v-if="step.notifications[0]?.channel === 'mail'"><label class="grid gap-1 text-sm">Email addresses<input :value="(step.notifications[0]?.emails ?? []).join(', ')" class="min-h-10 rounded-md border border-input bg-background px-3" placeholder="team@example.com" @change="patchNotification(index, 'emails', ($event.target as HTMLInputElement).value.split(',').map((value) => value.trim()).filter(Boolean))"></label><label class="grid gap-1 text-sm">Email subject<input :value="step.notifications[0]?.subject ?? ''" class="min-h-10 rounded-md border border-input bg-background px-3" @input="patchNotification(index, 'subject', ($event.target as HTMLInputElement).value)"></label><label class="grid gap-1 text-sm">Email message<textarea :value="step.notifications[0]?.message ?? ''" rows="3" class="rounded-md border border-input bg-background p-3" @input="patchNotification(index, 'message', ($event.target as HTMLTextAreaElement).value)" /></label></template>
                <fieldset class="space-y-2"><legend class="text-sm font-medium">Transaction types (all when none selected)</legend><div class="flex flex-wrap gap-3"><label v-for="registry in registries ?? []" :key="registry.type" class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="step.notifications[0]?.model_types?.includes(registry.type) ?? false" @change="patchNotification(index, 'model_types', ($event.target as HTMLInputElement).checked ? [...(step.notifications[0]?.model_types ?? []), registry.type] : (step.notifications[0]?.model_types ?? []).filter((type) => type !== registry.type))">{{ registry.label }}</label></div></fieldset>
            </section>
        </article>
        <p v-if="steps.length === 0" class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">Add at least one approval step.</p>
    </section>
</template>
