import { execSync, spawn } from 'node:child_process'
import { chromium } from 'playwright-core'
const tinker = (c) => execSync(`php artisan tinker --execute=${JSON.stringify(c)}`, { stdio: 'pipe' })
tinker(`App\\Models\\User::where('email','t@local')->delete(); App\\Models\\User::forceCreate(['name'=>'T','email'=>'t@local','password'=>bcrypt('pw-12345678'),'email_verified_at'=>now()]);`)
const server = spawn('php', ['artisan', 'serve', '--port=8767', '--no-reload'], { stdio: 'ignore', detached: true })
const b = await chromium.launch()
try {
  await new Promise((r) => setTimeout(r, 1500))
  const p = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage()
  const errs = []; p.on('pageerror', (e) => errs.push(e.message))
  await p.goto('http://127.0.0.1:8767/login'); await p.fill('input[type=email]', 't@local'); await p.fill('input[type=password]', 'pw-12345678'); await p.click('button[type=submit]'); await p.waitForURL((u) => !u.toString().includes('login'))
  await p.goto('http://127.0.0.1:8767/agent-alerts'); await p.waitForLoadState('networkidle')
  await p.getByRole('button', { name: '30 jours', exact: true }).click(); await p.waitForResponse((r) => r.url().includes('month'), { timeout: 60000 }); await p.waitForTimeout(1000)
  const wraps = await p.evaluate(() => [...document.querySelectorAll('.flex-wrap')].filter((el) => { const r = [...el.children].filter((c) => c.offsetParent).map((c) => c.getBoundingClientRect()); return r.length > 1 && r.some((x) => x.top >= r[0].bottom - 1) }).map((el) => el.innerText.replace(/\s+/g, ' ').slice(0, 80)))
  console.log('retours à la ligne', wraps)
  await p.screenshot({ path: 'storage/ui-check/agent-alerts-30-jours.png', fullPage: true })
  await p.locator('tbody tr').first().click(); await p.waitForResponse((r) => r.url().includes('/agent-alerts/agent'), { timeout: 60000 }); await p.waitForTimeout(1500)
  await p.screenshot({ path: 'storage/ui-check/agent-alerts-fenetre.png' })
  console.log('errors', errs)
} finally { await b.close(); process.kill(-server.pid); tinker(`App\\Models\\User::where('email','t@local')->delete();`) }
