<script setup>
import { Link } from '@inertiajs/vue3'
import { onMounted, ref } from 'vue'
import { Building2, Clock, FileText, FlaskConical, Hash, LayoutGrid, Phone, ShieldAlert, Users, Workflow } from 'lucide-vue-next'
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
// Check for routing log errors
const hasRoutingErrors = ref(false)
const hasInvalidRouting = ref(false)

async function checkRoutingErrors() {
    try {
        const res = await fetch('/data/routing-logs/has-errors')
        const data = await res.json()
        hasRoutingErrors.value = data.has_errors
    } catch (error) {
        console.error('Error checking routing errors:', error)
    }
}

async function checkInvalidRouting() {
    try {
        const res = await fetch('/data/phonenumbers/has-invalid-routing')
        const data = await res.json()
        hasInvalidRouting.value = data.has_invalid_routing
    } catch (error) {
        console.error('Error checking invalid routing:', error)
    }
}

onMounted(() => {
    checkRoutingErrors()
    checkInvalidRouting()
    // Check every 60 seconds
    setInterval(checkRoutingErrors, 60000)
    setInterval(checkInvalidRouting, 60000)
})

// Ajouter les items de nav ici. Chaque item correspond à une page dans resources/js/pages/.
const navItems = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Appels', href: '/calls', icon: Phone },
    { title: 'Providers', href: '/providers', icon: Users },
    { title: 'Companies', href: '/companies', icon: Building2 },
    { title: 'Sources', href: '/sources', icon: Workflow },
    { title: 'Numéros', href: '/phonenumbers', icon: Hash, badge: () => hasInvalidRouting.value ? 'error' : null },
    { title: 'Blacklists', href: '/blacklists', icon: ShieldAlert },
    { title: 'Assignment History', href: '/assignment-history', icon: Clock },
    { title: 'Routing Logs', href: '/routing-logs', icon: FileText, badge: () => hasRoutingErrors.value ? 'error' : null },
    { title: 'API Tester', href: '/api-tester', icon: FlaskConical },
]
</script>

<template>
    <Sidebar collapsible="offcanvas" variant="sidebar">
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
