<script setup>
import { Link } from '@inertiajs/vue3'
import { LayoutGrid } from 'lucide-vue-next'
import NavMain from '@/components/NavMain.vue'
import NavUser from '@/components/NavUser.vue'
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar'
import { dashboard } from '@/routes'

// ─── Navigation ──────────────────────────────────────────────────────────────
// Ajouter les items de nav ici. Chaque item correspond à une page dans resources/js/pages/.
const navItems = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    // { title: 'Utilisateurs', href: '/users',  icon: Users },
    // { title: 'Commandes',    href: '/orders', icon: ShoppingCart },
]
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-md bg-gray-900 text-white"
                            >
                                <span class="text-xs font-bold">118</span>
                            </div>
                            <span class="font-semibold text-gray-900">Dashboard</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="navItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
</template>
