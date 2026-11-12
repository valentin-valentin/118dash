<script setup>
import { Link } from '@inertiajs/vue3'
import { onMounted, onUnmounted, ref } from 'vue'
import { Building2, CalendarRange, ChartColumn, Clock, FileText, FlaskConical, Hash, Headphones, LayoutGrid, MessagesSquare, MonitorPlay, Phone, PhoneCall, PhoneOff, Server, ShieldAlert, Store, TriangleAlert, Users, Wallet, Workflow } from 'lucide-vue-next'
import NavMain from '@/components/NavMain.vue'
import { useBrandRequests } from '@/composables/useBrandRequests'
import { useReportedCalls } from '@/composables/useReportedCalls'
import { useRoutingErrors } from '@/composables/useRoutingErrors'
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
// Erreurs de routage des dernières 24 h : leur nombre en pastille rouge, relu toutes les 10 s (état partagé)
const { count: routingErrors, refresh: checkRoutingErrors } = useRoutingErrors()
let routingTimer = null
onUnmounted(() => clearInterval(routingTimer))
const hasInvalidRouting = ref(false)

async function checkInvalidRouting() {
    try {
        const res = await fetch('/data/phonenumbers/has-invalid-routing')
        const data = await res.json()
        hasInvalidRouting.value = data.has_invalid_routing
    } catch (error) {
        console.error('Error checking invalid routing:', error)
    }
}

// Marques demandées par les agents, à traiter (état partagé : pas de clignotement d'une page à l'autre)
const { count: brandRequests, refresh: checkBrandRequests } = useBrandRequests()
// Appels signalés par l'agent aujourd'hui (pastille rouge, état partagé)
const { count: reportedCalls, refresh: checkReportedCalls } = useReportedCalls()
let brandTimer = null
onUnmounted(() => clearInterval(brandTimer))

onMounted(() => {
    checkBrandRequests()
    checkReportedCalls()
    brandTimer = setInterval(() => {
        checkBrandRequests(true)
        checkReportedCalls(true)
    }, 60000)
    checkRoutingErrors()
    checkInvalidRouting()
    routingTimer = setInterval(checkRoutingErrors, 10000)
    setInterval(checkInvalidRouting, 60000)
})

// Ajouter les items de nav ici. Chaque item correspond à une page dans resources/js/pages/.
const navItems = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Assistant', href: '/assistant', icon: MessagesSquare },
    { title: 'Appels', href: '/calls', icon: Phone },
    { title: 'Appels rejetés', href: '/rejected-calls', icon: PhoneOff },
    { title: 'Appels à vérifier', href: '/calls-to-review', icon: Headphones, alert: () => reportedCalls.value || null },
    { title: 'Alertes agents', href: '/agent-alerts', icon: TriangleAlert },
    { title: 'Attentes et agents', href: '/pilotage', icon: CalendarRange },
    { title: 'Performance CC', href: '/callcenter-performance', icon: ChartColumn },
    { title: 'Providers', href: '/providers', icon: Users },
    { title: 'Companies', href: '/companies', icon: Building2 },
    { title: 'Marques', href: '/brands', icon: Store, badge: () => brandRequests.value?.brands || null },
    { title: 'Sources', href: '/sources', icon: Workflow },
    { title: 'Soldes', href: '/soldes', icon: Wallet },
    { title: 'Numéros', href: '/phonenumbers', icon: Hash, badge: () => hasInvalidRouting.value ? 'error' : null },
    { title: 'Serveur SIP', href: '/sip-server', icon: Server },
    { title: 'Superviseur', href: '/superviseur/settings', icon: MonitorPlay },
    { title: 'Blacklists', href: '/blacklists', icon: ShieldAlert },
    { title: 'Assignment History', href: '/assignment-history', icon: Clock },
    { title: 'Routing Logs', href: '/routing-logs', icon: FileText, alert: () => routingErrors.value || null },
    { title: 'API Tester', href: '/api-tester', icon: FlaskConical },
    { title: 'Testeur de softphone', href: '/softphone-tester', icon: PhoneCall },
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
