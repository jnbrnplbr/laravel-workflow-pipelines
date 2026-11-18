<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import BodyTeleport from '../Components/BodyTeleport.vue';
import { pipelinesIndex, pipelinesStore, pipelinesUpdate, pipelinesDestroy, pipelinesRestore, routeUrl } from '@/wayfinder/workflow-pipelines';

type Pipeline = { id: number; model_type: string; unit_id: number | null; workflow_id: number; workflow?: { name: string }; deleted_at: string | null };
type SelectOption = { type?: string; id?: number; label?: string; name: string };
const props = defineProps<{
    pipelines: { data: Pipeline[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    filter: string; can: { manage: boolean }; transactionTypes: SelectOption[];
    workflows: Array<{ id: number; name: string }>; organizations: Array<{ id: number; name: string }>;
}>();
const editing = ref<Pipeline | null>(null);
const showForm = ref(false);
const form = useForm({ model_type: '', unit_id: '' as number | string, workflow_id: '' as number | string });
const breadcrumbs = [{ title: 'Workflow pipelines', href: routeUrl(pipelinesIndex()) }];
const heading = computed(() => editing.value ? 'Edit pipeline' : 'Create pipeline');
function open(pipeline?: Pipeline) {
    editing.value = pipeline ?? null;
    showForm.value = true;
    form.clearErrors();
    form.model_type = pipeline?.model_type ?? '';
    form.unit_id = pipeline?.unit_id ?? '';
    form.workflow_id = pipeline?.workflow_id ?? '';
}
function save() {
    const done = () => { form.reset(); editing.value = null; showForm.value = false; };
    editing.value ? form.put(routeUrl(pipelinesUpdate(editing.value.id)), { onSuccess: done }) : form.post(routeUrl(pipelinesStore()), { onSuccess: done });
}
</script>

<template>
    <Head title="Workflow pipelines" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <main class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
            <header class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-semibold">Workflow pipelines</h1><p class="text-sm text-muted-foreground">Choose the workflow used for each transaction type and organization.</p></div><ActionButton v-if="can.manage" @click="open()">Create pipeline</ActionButton></header>
            <nav class="flex flex-wrap gap-2" aria-label="Pipeline filters"><ActionButton v-for="item in ['active', 'all', 'deleted']" :key="item" type="button" :variant="filter === item ? 'default' : 'outline'" @click="router.get(routeUrl(pipelinesIndex()), { filter: item })">{{ item }}</ActionButton></nav>
            <div v-if="pipelines.data.length" class="space-y-3">
                <article v-for="pipeline in pipelines.data" :key="pipeline.id" class="flex flex-col gap-4 rounded-lg border border-border bg-card p-4 text-card-foreground sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0"><h2 class="break-all font-medium">{{ transactionTypes.find((item) => item.type === pipeline.model_type)?.label ?? pipeline.model_type }}</h2><p class="text-sm text-muted-foreground">{{ pipeline.unit_id ? organizations.find((item) => item.id === pipeline.unit_id)?.name ?? `Organization ${pipeline.unit_id}` : 'Global default' }} · {{ pipeline.workflow?.name ?? `Workflow ${pipeline.workflow_id}` }}</p><span v-if="pipeline.deleted_at" class="text-xs text-muted-foreground">Deleted</span></div>
                    <div v-if="can.manage" class="flex flex-wrap gap-2"><ActionButton v-if="!pipeline.deleted_at" variant="outline" @click="open(pipeline)">Edit</ActionButton><ActionButton v-if="!pipeline.deleted_at" variant="destructive" @click="router.delete(routeUrl(pipelinesDestroy(pipeline.id)))">Delete</ActionButton><ActionButton v-else variant="outline" @click="router.post(routeUrl(pipelinesRestore(pipeline.id)))">Restore</ActionButton></div>
                </article>
            </div>
            <p v-else class="rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground">No pipelines match this filter.</p>
            <nav v-if="pipelines.links.length > 3" class="flex flex-wrap gap-2" aria-label="Pagination"><Link v-for="link in pipelines.links" :key="link.label" :href="link.url ?? '# '" :class="['rounded-md border border-border px-3 py-2 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-card text-card-foreground', !link.url && 'pointer-events-none opacity-50']" v-html="link.label" /></nav>
        </main>
        <BodyTeleport>
            <div v-if="showForm" class="fixed inset-0 z-50 flex items-end justify-center bg-foreground/50 p-0 sm:items-center sm:p-4" role="presentation" @click.self="showForm = false">
                <section role="dialog" aria-modal="true" aria-labelledby="pipeline-dialog-title" class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-t-xl border border-border bg-card p-5 text-card-foreground sm:rounded-xl">
                    <h2 id="pipeline-dialog-title" class="text-lg font-semibold">{{ heading }}</h2>
                    <form class="mt-4 space-y-4" @submit.prevent="save">
                        <label class="grid gap-1 text-sm">Transaction type<select v-model="form.model_type" required class="min-h-10 rounded-md border border-input bg-background px-3"><option value="">Choose a type</option><option v-for="type in transactionTypes" :key="type.type" :value="type.type">{{ type.label }}</option></select></label><p v-if="form.errors.model_type" class="text-sm text-destructive">{{ form.errors.model_type }}</p>
                        <label class="grid gap-1 text-sm">Organization<select v-model="form.unit_id" class="min-h-10 rounded-md border border-input bg-background px-3"><option value="">Global default</option><option v-for="organization in organizations" :key="organization.id" :value="organization.id">{{ organization.name }}</option></select></label><p v-if="form.errors.unit_id" class="text-sm text-destructive">{{ form.errors.unit_id }}</p>
                        <label class="grid gap-1 text-sm">Workflow<select v-model="form.workflow_id" required class="min-h-10 rounded-md border border-input bg-background px-3"><option value="">Choose a workflow</option><option v-for="workflow in workflows" :key="workflow.id" :value="workflow.id">{{ workflow.name }}</option></select></label><p v-if="form.errors.workflow_id" class="text-sm text-destructive">{{ form.errors.workflow_id }}</p>
                        <div class="flex flex-wrap justify-end gap-2"><ActionButton type="button" variant="outline" @click="showForm = false">Cancel</ActionButton><ActionButton type="submit" :disabled="form.processing">Save pipeline</ActionButton></div>
                    </form>
                </section>
            </div>
        </BodyTeleport>
    </AppLayout>
</template>
