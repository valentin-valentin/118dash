/**
 * Token CSRF à jour, lu depuis le cookie XSRF-TOKEN que Laravel renvoie à chaque réponse.
 * À préférer au <meta name="csrf-token">, figé au chargement initial de la page
 * et donc périmé dès que la session est régénérée (login, logout...) sans rechargement complet.
 */
export function xsrfToken(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}
