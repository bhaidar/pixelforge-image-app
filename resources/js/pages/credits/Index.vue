<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatNanos } from '@/lib/money';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    options: number[];
    entries: { type: string; amount_nanos: number; created_at: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/credits' }];
const balance = computed(() => usePage<SharedData>().props.auth.user.balance_nanos);

const buy = (amount: number) => router.post('/credits', { amount }, { preserveScroll: true });
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
            <Card>
                <CardHeader>
                    <CardTitle>Your balance: {{ formatNanos(balance) }}</CardTitle>
                    <CardDescription>
                        Credits are US dollars. Buying here is a demo: no real payment is taken. Credit reserved for generations in progress is
                        shown as spent until they finish.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-wrap gap-3">
                    <Button v-for="amount in options" :key="amount" @click="buy(amount)">Buy ${{ amount }}</Button>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Credit history</CardTitle></CardHeader>
                <CardContent>
                    <ul class="divide-y text-sm">
                        <li v-for="(entry, index) in entries" :key="index" class="flex justify-between py-2">
                            <span class="capitalize">{{ entry.type === 'grant' ? 'Welcome credit' : 'Purchase' }}</span>
                            <span>{{ formatNanos(entry.amount_nanos) }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
