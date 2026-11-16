<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatNanos } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';

interface GenerationRow {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status: 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';
    estimated_min_nanos: number;
    estimated_max_nanos: number;
    charge_nanos: number | null;
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    created_at: string;
}

const props = defineProps<{ generations: GenerationRow[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/generations' }];

const hasActive = computed(() => props.generations.some((g) => g.status === 'pending' || g.status === 'queued' || g.status === 'processing'));

let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => {
        if (hasActive.value) {
            router.reload({ only: ['generations', 'auth'] });
        }
    }, 3000);
});

onBeforeUnmount(() => clearInterval(timer));

const statusStyles: Record<string, string> = {
    pending: 'bg-muted text-muted-foreground',
    queued: 'bg-muted text-muted-foreground',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    succeeded: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};

// Pending means "not yet accepted by Pixelforge"; to the user that is the same as queued.
const statusLabel = (status: string) => (status === 'pending' ? 'queued' : status);
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
            <div v-if="generations.length === 0" class="rounded-lg border p-8 text-center">
                <p class="mb-4 text-muted-foreground">You have not generated anything yet.</p>
                <Button as-child><Link href="/generate">Generate your first image</Link></Button>
            </div>

            <Card v-for="g in generations" :key="g.id">
                <CardContent class="flex gap-4 p-4">
                    <div class="flex h-32 w-32 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted text-center text-xs text-muted-foreground">
                        <a v-if="g.image_url" :href="g.image_url" target="_blank" rel="noopener">
                            <img :src="g.image_url" :alt="g.prompt" class="h-full w-full object-cover" />
                        </a>
                        <span v-else-if="g.status === 'succeeded' && g.image_expired">Image expired</span>
                        <span v-else-if="g.status === 'failed'">No image</span>
                        <span v-else class="animate-pulse">Working on it...</span>
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <p class="line-clamp-2 font-medium">{{ g.prompt }}</p>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            <span :class="['rounded-full px-2 py-0.5 font-medium capitalize', statusStyles[g.status]]">{{ statusLabel(g.status) }}</span>
                            <span>{{ g.size }}</span>
                            <span class="capitalize">{{ g.quality }}</span>
                            <span>{{ new Date(g.created_at).toLocaleString() }}</span>
                        </div>

                        <p v-if="g.status === 'failed'" class="text-sm text-destructive">
                            {{ g.error_message ?? 'Generation failed.' }} You were not charged.
                        </p>

                        <dl class="mt-auto grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-muted-foreground">Estimated</dt>
                                <dd>{{ formatNanos(g.estimated_min_nanos) }} to {{ formatNanos(g.estimated_max_nanos) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Actual cost</dt>
                                <dd class="font-semibold">
                                    <template v-if="g.status === 'succeeded' || g.status === 'failed'">{{ formatNanos(g.charge_nanos) }}</template>
                                    <template v-else>Pending</template>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
