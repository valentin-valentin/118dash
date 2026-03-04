import type { InertiaLinkProps } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import type { ComputedRef, DeepReadonly } from 'vue';
import { computed, readonly } from 'vue';
import { toUrl } from '@/lib/utils';

export type UseCurrentUrlReturn = {
    currentUrl: DeepReadonly<ComputedRef<string>>;
    isCurrentUrl: (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
    ) => boolean;
    whenCurrentUrl: <T, F = null>(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: T,
        ifFalse?: F,
    ) => T | F;
};

const page = usePage();
const currentUrlReactive = computed(
    () => new URL(page.url, window?.location.origin).pathname,
);

export function useCurrentUrl(): UseCurrentUrlReturn {
    function isCurrentUrl(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
    ) {
        const urlToCompare = currentUrl ?? currentUrlReactive.value;
        const urlString = toUrl(urlToCheck);

        if (!urlString.startsWith('http')) {
            // Correspondance exacte
            if (urlString === urlToCompare) {
                return true;
            }
            // Correspondance pour les sous-pages (ex: /blacklists correspond à /blacklists/create)
            // Ne pas activer pour le dashboard sur toutes les pages
            if (urlString !== '/' && urlToCompare.startsWith(urlString + '/')) {
                return true;
            }
            return false;
        }

        try {
            const absoluteUrl = new URL(urlString);
            const pathname = absoluteUrl.pathname;

            // Correspondance exacte
            if (pathname === urlToCompare) {
                return true;
            }
            // Correspondance pour les sous-pages
            if (pathname !== '/' && urlToCompare.startsWith(pathname + '/')) {
                return true;
            }
            return false;
        } catch {
            return false;
        }
    }

    function whenCurrentUrl(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: any,
        ifFalse: any = null,
    ) {
        return isCurrentUrl(urlToCheck) ? ifTrue : ifFalse;
    }

    return {
        currentUrl: readonly(currentUrlReactive),
        isCurrentUrl,
        whenCurrentUrl,
    };
}
