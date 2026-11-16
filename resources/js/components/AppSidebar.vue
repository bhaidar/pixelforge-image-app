<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { formatNanos } from '@/lib/money';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, Coins, History, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Generate', href: '/generate', icon: Sparkles },
    { title: 'History', href: '/generations', icon: History },
    { title: 'Credits', href: '/credits', icon: Coins },
    ...(page.props.auth.user.is_admin ? [{ title: 'Admin report', href: '/admin/report', icon: BarChart3 }] : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <div class="px-2 pb-1 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
                Balance: <Link href="/credits" class="font-semibold text-foreground">{{ formatNanos(page.props.auth.user.balance_nanos) }}</Link>
            </div>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
