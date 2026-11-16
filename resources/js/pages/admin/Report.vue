<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatNanos } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface Bucket {
    date: string;
    generations: number;
    charged_nanos: number;
    provider_nanos: number;
    profit_nanos: number;
}
interface UserBucket extends Bucket {
    user: string;
}

const props = defineProps<{
    start: string;
    end: string;
    rows: UserBucket[];
    days: Bucket[];
    totals: Omit<Bucket, 'date'>;
    reconciliation: {
        available: boolean;
        error: string | null;
        days: { date: string; pixelforge_nanos: number; ours_nanos: number; diff_nanos: number }[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin report', href: '/admin/report' }];
const range = reactive({ start: props.start, end: props.end });

const apply = () => router.get('/admin/report', range, { preserveState: true });
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
            <form class="flex items-end gap-3" @submit.prevent="apply">
                <label class="grid gap-1 text-sm">Start (UTC)<Input v-model="range.start" type="date" /></label>
                <label class="grid gap-1 text-sm">End (UTC)<Input v-model="range.end" type="date" /></label>
                <Button type="submit">Apply</Button>
            </form>

            <div class="grid gap-4 md:grid-cols-4">
                <Card>
                    <CardHeader><CardDescription>Credit spend (charged)</CardDescription><CardTitle>{{ formatNanos(totals.charged_nanos) }}</CardTitle></CardHeader>
                </Card>
                <Card>
                    <CardHeader><CardDescription>Provider cost</CardDescription><CardTitle>{{ formatNanos(totals.provider_nanos) }}</CardTitle></CardHeader>
                </Card>
                <Card>
                    <CardHeader><CardDescription>Profit</CardDescription><CardTitle>{{ formatNanos(totals.profit_nanos) }}</CardTitle></CardHeader>
                </Card>
                <Card>
                    <CardHeader><CardDescription>Generations billed</CardDescription><CardTitle>{{ totals.generations }}</CardTitle></CardHeader>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Reconciliation with Pixelforge</CardTitle>
                    <CardDescription>Provider cost per UTC day (by completion) against what GET /v1/usage reports for this API key.</CardDescription>
                </CardHeader>
                <CardContent>
                    <p v-if="!reconciliation.available" class="text-sm text-destructive">Could not load Pixelforge usage: {{ reconciliation.error }}</p>
                    <table v-else class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr><th class="py-1">Day</th><th>Pixelforge</th><th>Ours</th><th>Difference</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="day in reconciliation.days" :key="day.date" class="border-t">
                                <td class="py-1">{{ day.date }}</td>
                                <td>{{ formatNanos(day.pixelforge_nanos) }}</td>
                                <td>{{ formatNanos(day.ours_nanos) }}</td>
                                <td :class="day.diff_nanos === 0 ? 'text-green-600' : 'font-semibold text-destructive'">
                                    {{ day.diff_nanos === 0 ? 'Match' : formatNanos(day.diff_nanos) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>By day</CardTitle></CardHeader>
                <CardContent>
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr><th class="py-1">Day</th><th>Generations</th><th>Charged</th><th>Provider cost</th><th>Profit</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="day in days" :key="day.date" class="border-t">
                                <td class="py-1">{{ day.date }}</td>
                                <td>{{ day.generations }}</td>
                                <td>{{ formatNanos(day.charged_nanos) }}</td>
                                <td>{{ formatNanos(day.provider_nanos) }}</td>
                                <td>{{ formatNanos(day.profit_nanos) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>By day and user</CardTitle></CardHeader>
                <CardContent>
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr><th class="py-1">Day</th><th>User</th><th>Generations</th><th>Charged</th><th>Provider cost</th><th>Profit</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.date + row.user" class="border-t">
                                <td class="py-1">{{ row.date }}</td>
                                <td>{{ row.user }}</td>
                                <td>{{ row.generations }}</td>
                                <td>{{ formatNanos(row.charged_nanos) }}</td>
                                <td>{{ formatNanos(row.provider_nanos) }}</td>
                                <td>{{ formatNanos(row.profit_nanos) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
