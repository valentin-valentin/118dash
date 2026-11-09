#!/usr/bin/env node
/**
 * Vérification du système de design (docs/design-system.md, section 6), sur les pages données :
 *
 *   npm run ui:check -- /brands/requests/laposte /softphone-tester
 *
 * Pour chaque page, à 1440 px :
 *   - capture de la page entière dans storage/ui-check/ (à regarder bloc par bloc) ;
 *   - débordement horizontal de la page ;
 *   - retours à la ligne dans les lignes flex-wrap (chacun doit être voulu) ;
 *   - éléments qui débordent de leur bloc ou dont le texte est coupé, zones qui défilent horizontalement ;
 *   - nombre de boutons principaux (noirs) visibles : un seul attendu ;
 *   - erreurs JavaScript.
 * Un retour à la ligne ou un texte coupé VOULU se déclare dans le template avec l'attribut data-ui-ok (sur l'élément
 * ou un parent), pour qu'il ne soit plus signalé.
 * Lance son propre serveur (port 8766) avec un utilisateur temporaire, supprimé à la fin.
 * Code de sortie 1 s'il y a un problème : l'écran n'est pas terminé.
 */
import { execSync, spawn } from 'node:child_process'
import { mkdirSync } from 'node:fs'
import { chromium } from 'playwright-core'

const PORT = 8766
const BASE = `http://127.0.0.1:${PORT}`
const EMAIL = 'ui-check@local'
const PASSWORD = 'ui-check-password'
const OUT = 'storage/ui-check'
const pages = process.argv.slice(2)
if (!pages.length) {
    console.error('Usage : npm run ui:check -- /chemin [/autre-chemin…]')
    process.exit(2)
}

const tinker = (code) => execSync(`php artisan tinker --execute=${JSON.stringify(code)}`, { stdio: 'pipe' })
tinker(`App\\Models\\User::where('email','${EMAIL}')->delete(); App\\Models\\User::forceCreate(['name'=>'Vérification','email'=>'${EMAIL}','password'=>bcrypt('${PASSWORD}'),'email_verified_at'=>now()]);`)
// Serveur dans son propre groupe de processus : arrêté en entier à la fin (artisan serve lance des processus enfants)
const server = spawn('php', ['artisan', 'serve', `--port=${PORT}`, '--no-reload'], { env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore', detached: true })
const stopServer = () => {
    try {
        process.kill(-server.pid)
    } catch {
        // déjà arrêté
    }
}
mkdirSync(OUT, { recursive: true })

// Analyse de la page, dans le navigateur (hors menu latéral, en-tête fixe et bouton du softphone)
function audit() {
    const inScope = (el) => !el.closest('[data-sidebar], .fixed, [data-ui-ok]') && el.offsetParent !== null
    const label = (el) => (el.innerText || el.getAttribute('aria-label') || el.tagName).replace(/\s+/g, ' ').trim().slice(0, 70)
    const wraps = [...document.querySelectorAll('.flex-wrap')].filter(inScope).filter((el) => {
        const r = [...el.children].filter((c) => c.offsetParent).map((c) => c.getBoundingClientRect())
        return r.length > 1 && r.some((x) => x.top >= r[0].bottom - 1)
    }).map(label)
    const overflow = [...document.querySelectorAll('main *, [data-slot="sidebar-inset"] *')].filter(inScope).filter((el) => {
        const s = getComputedStyle(el)
        // Zone à défilement horizontal : signalée si son contenu dépasse (tableau coupé, colonne cachée)
        if (['auto', 'scroll'].includes(s.overflowX)) return el.scrollWidth > el.clientWidth + 1
        return el.scrollWidth > el.clientWidth + 1 && (s.overflowX === 'hidden' || s.textOverflow === 'ellipsis' || el.children.length === 0)
    }).map(label)
    // Boutons principaux : les interrupteurs (role=switch) ont aussi la couleur primaire, ce ne sont pas des boutons d'action
    const primary = [...document.querySelectorAll('button.bg-primary:not([role=switch]), a.bg-primary')].filter(inScope).map(label)
    return { pageOverflow: document.documentElement.scrollWidth > window.innerWidth, wraps, overflow: [...new Set(overflow)].slice(0, 15), primary }
}

let failed = false
const browser = await chromium.launch()
try {
    await new Promise((r) => setTimeout(r, 1500))
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage()
    const errors = []
    page.on('pageerror', (e) => errors.push(e.message))
    await page.goto(`${BASE}/login`)
    await page.fill('input[type=email]', EMAIL)
    await page.fill('input[type=password]', PASSWORD)
    await page.click('button[type=submit]')
    await page.waitForURL((u) => !u.toString().includes('login')).catch(() => {
        throw new Error(`Connexion impossible sur ${BASE} : le port ${PORT} est-il déjà pris par un autre serveur ?`)
    })

    for (const path of pages) {
        errors.length = 0
        await page.goto(BASE + path)
        await page.waitForLoadState('networkidle').catch(() => {})
        await page.waitForTimeout(800)
        const file = `${OUT}/${path.replace(/\W+/g, '-').replace(/^-|-$/g, '') || 'accueil'}.png`
        await page.screenshot({ path: file, fullPage: true })
        const r = await page.evaluate(audit)
        const problems = [
            r.pageOverflow && 'La page déborde horizontalement',
            ...r.wraps.map((t) => `Retour à la ligne (voulu ?) : « ${t} »`),
            ...r.overflow.map((t) => `Contenu qui déborde ou texte coupé : « ${t} »`),
            r.primary.length > 1 && `${r.primary.length} boutons principaux (noirs) : ${r.primary.map((t) => `« ${t} »`).join(', ')}`,
            ...errors.map((e) => `Erreur JavaScript : ${e}`),
        ].filter(Boolean)
        console.log(`\n${path}  →  ${file}`)
        if (problems.length) {
            failed = true
            problems.forEach((p) => console.log(`  ✗ ${p}`))
        } else {
            console.log('  ✓ aucun problème détecté')
        }
    }
} finally {
    await browser.close()
    stopServer()
    tinker(`App\\Models\\User::where('email','${EMAIL}')->delete();`)
}
console.log(failed ? '\nÀ corriger, ou à justifier dans le compte rendu. Puis regarder chaque capture bloc par bloc.' : '\nRegarder maintenant chaque capture bloc par bloc.')
process.exit(failed ? 1 : 0)
