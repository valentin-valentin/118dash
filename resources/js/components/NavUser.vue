<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut } from 'lucide-vue-next';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { logout } from '@/routes';

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <SidebarMenuButton size="lg" as-child>
                <Link
                    :href="logout()"
                    @click="handleLogout"
                    as="button"
                    data-test="logout-button"
                >
                    <LogOut class="size-4" />
                    <span>Déconnexion</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
