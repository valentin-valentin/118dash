<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

/**
 * Largeur du contenu, décidée par chaque page, en un seul endroit :
 *   - full : tableaux, statistiques, grilles (toute la largeur) ;
 *   - form : formulaires, fiches, réglages, pages d'action (largeur limitée, lisible).
 */
type Props = {
    breadcrumbs?: BreadcrumbItem[];
    width?: 'full' | 'form';
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    width: 'full',
});

const WIDTHS = { full: 'w-full', form: 'w-full max-w-4xl' } as const;
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div :class="WIDTHS[props.width]">
            <slot />
        </div>
    </AppLayout>
</template>
