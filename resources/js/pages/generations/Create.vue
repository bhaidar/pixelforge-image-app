<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatNanos } from '@/lib/money';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ sizes: string[]; qualities: string[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];

const page = usePage<SharedData>();
const balance = computed(() => page.props.auth.user.balance_nanos);

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const estimate = ref<{ min_nanos: number; max_nanos: number } | null>(null);
const estimateError = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let requestId = 0;

const references = computed(() => form.reference_images.filter((url) => url.trim() !== ''));

async function refreshEstimate() {
    if (form.prompt.trim() === '') {
        estimate.value = null;
        return;
    }

    const id = ++requestId;
    try {
        const { data } = await axios.post('/generations/estimate', {
            prompt: form.prompt,
            size: form.size,
            quality: form.quality,
            reference_images: references.value,
        });
        if (id === requestId) {
            estimate.value = data;
            estimateError.value = null;
        }
    } catch {
        if (id === requestId) {
            estimate.value = null;
            estimateError.value = 'Could not calculate the price. Check your reference image URLs.';
        }
    }
}

watch(
    () => [form.prompt, form.size, form.quality, references.value.join('\n')],
    () => {
        clearTimeout(timer);
        timer = setTimeout(refreshEstimate, 300);
    },
);

const canAfford = computed(() => !estimate.value || balance.value >= estimate.value.max_nanos);

function addReference() {
    if (form.reference_images.length < 4) {
        form.reference_images.push('');
    }
}

function submit() {
    form.transform((data) => ({ ...data, reference_images: references.value })).post('/generations');
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
            <Card>
                <CardHeader>
                    <CardTitle>Generate an image</CardTitle>
                    <CardDescription>Describe what you want. You only pay when an image is successfully generated.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="flex flex-col gap-5" @submit.prevent="submit">
                        <div class="grid gap-2">
                            <Label for="prompt">Prompt</Label>
                            <textarea
                                id="prompt"
                                v-model="form.prompt"
                                rows="4"
                                maxlength="4000"
                                class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                placeholder="A lighthouse on a cliff at dawn, oil painting"
                            />
                            <p v-if="form.errors.prompt" class="text-sm text-destructive">{{ form.errors.prompt }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label for="size">Size</Label>
                                <select id="size" v-model="form.size" class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm">
                                    <option v-for="size in props.sizes" :key="size" :value="size">{{ size }}</option>
                                </select>
                                <p v-if="form.errors.size" class="text-sm text-destructive">{{ form.errors.size }}</p>
                            </div>
                            <div class="grid gap-2">
                                <Label for="quality">Quality</Label>
                                <select id="quality" v-model="form.quality" class="h-9 rounded-md border border-input bg-background px-3 text-sm capitalize shadow-sm">
                                    <option v-for="quality in props.qualities" :key="quality" :value="quality">{{ quality }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label>Reference image URLs (optional, up to 4)</Label>
                            <div v-for="(_, index) in form.reference_images" :key="index" class="flex flex-col gap-1">
                                <div class="flex gap-2">
                                    <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/style.png" />
                                    <Button type="button" variant="ghost" @click="form.reference_images.splice(index, 1)">Remove</Button>
                                </div>
                                <p v-if="form.errors[`reference_images.${index}` as keyof typeof form.errors]" class="text-sm text-destructive">
                                    {{ form.errors[`reference_images.${index}` as keyof typeof form.errors] }}
                                </p>
                            </div>
                            <Button v-if="form.reference_images.length < 4" type="button" variant="outline" size="sm" class="w-fit" @click="addReference">
                                Add reference image
                            </Button>
                        </div>

                        <div class="rounded-lg border bg-muted/40 p-4 text-sm">
                            <div class="flex items-baseline justify-between">
                                <span class="font-medium">Estimated price</span>
                                <span v-if="estimate" class="text-lg font-semibold">
                                    {{ formatNanos(estimate.min_nanos) }} to {{ formatNanos(estimate.max_nanos) }}
                                </span>
                                <span v-else class="text-muted-foreground">Enter a prompt to see the price</span>
                            </div>
                            <p class="mt-2 text-muted-foreground">
                                The final price depends on how much the model thinks, which is only known once the image is done. You are charged the exact final amount,
                                never more than the top of the range.
                            </p>
                            <p v-if="estimateError" class="mt-2 text-destructive">{{ estimateError }}</p>
                            <p class="mt-2">
                                Your balance: <strong>{{ formatNanos(balance) }}</strong>
                            </p>
                            <p v-if="!canAfford" class="mt-2 font-medium text-destructive">
                                Your balance is lower than the maximum price.
                                <Link href="/credits" class="underline">Buy credits</Link>
                            </p>
                        </div>

                        <Button type="submit" :disabled="form.processing || form.prompt.trim() === '' || !canAfford">
                            {{ form.processing ? 'Submitting...' : 'Generate' }}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
