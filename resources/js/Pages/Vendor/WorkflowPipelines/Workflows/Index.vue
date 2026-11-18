<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { workflowsCreate, workflowsEdit, workflowsDestroy, workflowsDuplicate, workflowsRestore, workflowsIndex, routeUrl } from '@/wayfinder/workflow-pipelines';

type Workflow = { id: number; name: string; description: string | null; steps_count: number; usage_count: number; deleted_at: string | null };
const props = defineProps<{ workflows: { data: Workflow[]; links: Array<{ url: string | null; label: string; active: boolean }> }; filter: string; can: { manage: boolean } }>();
const breadcrumbs = [{ title: 'Workflows', href: routeUrl(workflowsIndex()) }];
function confirmDelete(workflow: Workflow) { if (window.confirm(`Delete workflow “${workflow.name}”?`)) router.delete(routeUrl(workflowsDestroy(workflow.id))); }
function duplicate(workflow: Workflow) { const name = window.prompt('Name for the copied workflow', `${workflow.name} (copy)`); if (name) router.post(routeUrl(workflowsDuplicate(workflow.id)), { name }); }
function filter(value: string) { router.get(routeUrl(workflowsIndex()), { filter: value }, { preserveState: true, replace: true }); }
</script>

<template>
    <Head title="Workflows" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <main class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
            <header class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-semibold">Workflows</h1><p class="text-sm text-muted-foreground">Define reusable approval steps for transactions.</p></div><Link v-if="can.manage" :href="routeUrl(workflowsCreate())"><ActionButton>Create workflow</ActionButton></Link></header>
            <nav class="flex flex-wrap gap-2" aria-label="Workflow filters"><ActionButton v-for="item in ['active', 'all', 'deleted']" :key="item" type="button" :variant="filter === item ? 'default' : 'outline'" @click="filter(item)">{{ item }}</ActionButton></nav>
            <div v-if="workflows.data.length" class="space-y-3">
                <article v-for="workflow in workflows.data" :key="workflow.id" class="flex flex-col gap-4 rounded-lg border border-border bg-card p-4 text-card-foreground sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h2 class="font-medium">{{ workflow.name }}</h2><span v-if="workflow.deleted_at" class="rounded-full bg-muted px-2 py-1 text-xs">Deleted</span><span v-if="workflow.usage_count" class="rounded-full bg-secondary px-2 py-1 text-xs">In use</span></div><p v-if="workflow.description" class="mt-1 break-words text-sm text-muted-foreground">{{ workflow.description }}</p><p class="mt-1 text-xs text-muted-foreground">{{ workflow.steps_count }} steps · {{ workflow.usage_count }} transactions</p></div>
                    <div v-if="can.manage" class="flex flex-wrap gap-2"><Link v-if="!workflow.deleted_at" :href="routeUrl(workflowsEdit(workflow.id))"><ActionButton variant="outline">Edit</ActionButton></Link><ActionButton v-if="!workflow.deleted_at" variant="outline" @click="duplicate(workflow)">Duplicate</ActionButton><ActionButton v-if="!workflow.deleted_at" variant="destructive" @click="confirmDelete(workflow)">Delete</ActionButton><ActionButton v-else variant="outline" @click="router.post(routeUrl(workflowsRestore(workflow.id)))">Restore</ActionButton></div>
                </article>
            </div>
            <p v-else class="rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground">No workflows match this filter.</p>
            <nav v-if="workflows.links.length > 3" class="flex flex-wrap gap-2" aria-label="Pagination"><Link v-for="link in workflows.links" :key="link.label" :href="link.url ?? '# '" :class="['rounded-md border border-border px-3 py-2 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'bg-card text-card-foreground', !link.url && 'pointer-events-none opacity-50']" v-html="link.label" /></nav>
        </main>
    </AppLayout>
</template>
