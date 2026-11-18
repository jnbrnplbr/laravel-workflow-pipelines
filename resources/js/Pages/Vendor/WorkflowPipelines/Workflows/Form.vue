<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import WorkflowStepsEditor, { type WorkflowStepInput } from '../Components/WorkflowStepsEditor.vue';
import { workflowsIndex, workflowsStore, workflowsUpdate, routeUrl } from '@/wayfinder/workflow-pipelines';

type Workflow = { id: number; name: string; description: string | null; steps: WorkflowStepInput[] };
const props = defineProps<{ workflow: Workflow | null; registries: Array<{ type: string; label: string; fields: Record<string, string> }>; roles?: Array<{ id: number; name: string }>; statuses?: Array<{ id: number; name: string }> }>();
const form = useForm({ name: props.workflow?.name ?? '', description: props.workflow?.description ?? '', steps: props.workflow?.steps?.map((step, i) => ({
    ...step,
    client_key: String(step.id ?? `step-${i + 1}`),
    rules: (step.rules ?? []).map((rule) => ({ ...rule, target_step_ids: rule.target_step_ids ?? [], editable_fields: rule.editable_fields ?? [], model_types: rule.model_types ?? [] })),
    notifications: (step.notifications ?? []).map((notification) => ({ ...notification, events: notification.events ?? [], recipients: notification.recipients ?? [], emails: notification.emails ?? [], model_types: notification.model_types ?? [] })),
})) ?? [] as WorkflowStepInput[] });
const breadcrumbs = [{ title: 'Workflows', href: routeUrl(workflowsIndex()) }, { title: props.workflow ? 'Edit workflow' : 'Create workflow' }];
function submit() { props.workflow ? form.put(routeUrl(workflowsUpdate(props.workflow.id))) : form.post(routeUrl(workflowsStore())); }
</script>

<template>
    <Head :title="workflow ? 'Edit workflow' : 'Create workflow'" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <main class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
            <header><h1 class="text-2xl font-semibold">{{ workflow ? 'Edit workflow' : 'Create workflow' }}</h1><p class="text-sm text-muted-foreground">Configure the workflow template and its ordered approval steps.</p></header>
            <form class="space-y-6" @submit.prevent="submit">
                <section class="grid gap-4 rounded-lg border border-border bg-card p-4 text-card-foreground">
                    <label class="grid gap-1 text-sm">Name<input v-model="form.name" required maxlength="255" class="min-h-10 rounded-md border border-input bg-background px-3"></label><p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                    <label class="grid gap-1 text-sm">Description<textarea v-model="form.description" rows="3" class="rounded-md border border-input bg-background p-3" /></label>
                </section>
                <WorkflowStepsEditor v-model="form.steps" :roles="roles ?? []" :statuses="statuses ?? []" :registries="registries" />
                <p v-if="form.errors.steps" class="text-sm text-destructive">{{ form.errors.steps }}</p>
                <div class="flex flex-wrap gap-3"><ActionButton type="submit" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save workflow' }}</ActionButton><Link :href="routeUrl(workflowsIndex())"><ActionButton type="button" variant="outline">Cancel</ActionButton></Link></div>
            </form>
            <p v-if="registries.length" class="text-xs text-muted-foreground">Registered transaction types: {{ registries.map((item) => item.label).join(', ') }}</p>
        </main>
    </AppLayout>
</template>
