// Full økt med fem simulerte telefoner. Kjør: node tests/e2e.js (krever php og playwright)
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const path = require('path');

const PORT = 8099;
const URL = `http://127.0.0.1:${PORT}/`;
const SHOTS = path.join(__dirname, 'shots');
const names = ['Ada', 'Ben', 'Celine', 'David', 'Eva'];
const shot = {};

(async () => {
  const php = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(__dirname, '..', 'web')], {
    env: { ...process.env, PHP_CLI_SERVER_WORKERS: '6' },
    stdio: 'ignore',
  });
  await new Promise(r => setTimeout(r, 800));
  const browser = await chromium.launch();
  const errors = [];
  let failed = false;
  const fail = (m) => { failed = true; console.log('FEIL:', m); };
  try {
    const pages = [];
    for (const n of names) {
      const ctx = await browser.newContext({ viewport: { width: 390, height: 780 }, deviceScaleFactor: 2 });
      const p = await ctx.newPage();
      p.on('pageerror', e => errors.push(n + ': ' + e.message));
      p.on('console', m => { if (m.type() === 'error' && !m.text().includes('navigator.vibrate')) errors.push(n + ': ' + m.text()); });
      p.on('dialog', d => d.accept());
      pages.push(p);
    }
    const snap = async (p, key) => {
      if (!shot[key]) { shot[key] = true; await p.screenshot({ path: path.join(SHOTS, key + '.png'), fullPage: true }); }
    };

    await pages[0].goto(URL);
    await snap(pages[0], '01-start');
    await pages[0].fill('#name', names[0]);
    await pages[0].click('[data-act=create]');
    await pages[0].waitForSelector('.codebox');
    const code = (await pages[0].textContent('.codebox')).trim();
    console.log('Romkode', code);

    // Feil romkode gir tydelig melding
    await pages[1].goto(URL);
    await pages[1].fill('#name', names[1]);
    await pages[1].fill('#code', 'QQQQQ');
    await pages[1].click('[data-act=join]');
    await pages[1].waitForFunction(() => document.getElementById('banner').textContent.includes('Fant ikke'));

    for (let i = 1; i < names.length; i++) {
      await pages[i].goto(URL + '?kode=' + code);
      if ((await pages[i].inputValue('#code')) !== code) fail('romkode ble ikke forhåndsutfylt fra lenken');
      await pages[i].fill('#name', names[i]);
      await pages[i].click('[data-act=join]');
      await pages[i].waitForSelector('.codebox');
    }
    await pages[0].waitForFunction(() => document.querySelectorAll('ul.players li').length === 5);
    await snap(pages[0], '02-lobby-vert');
    await pages[0].click('[data-act=start]');

    let rounds = 0, reloaded = false, paused = false, guard = 0;
    while (guard++ < 600) {
      let finished = 0;
      for (const [i, p] of pages.entries()) {
        const s = await p.evaluate(() => ({
          ready: !!document.querySelector('[data-act=ready]'),
          card: !!document.querySelector('.card'),
          next: !!document.querySelector('[data-act=next]'),
          listen: !!document.querySelector('.listen'),
          form: !!document.querySelector('[data-act=submit]'),
          nextRound: !!document.querySelector('[data-act=nextRound]'),
          reveal: !!document.querySelector('.reveal'),
          final: !!document.querySelector('[data-act=again]'),
          text: document.getElementById('app').innerText,
        }));
        if (s.final) { finished++; continue; }
        if (s.card && s.ready) { await snap(p, '03-hemmelig-kort'); await p.click('[data-act=ready]'); continue; }
        if (!s.card && s.text.includes('leser kortene')) await snap(p, '04-tilskuer-venter');
        if (s.next) {
          await snap(p, '05-min-replikk');
          if (!reloaded) { // gjenoppkobling midt i scenen: samme identitet og samme replikk
            reloaded = true;
            const before = await p.textContent('.linebox');
            await p.reload();
            await p.waitForSelector('.linebox');
            if ((await p.textContent('.linebox')) !== before) fail('gjenoppkobling ga ikke samme replikk');
          }
          await p.dblclick('[data-act=next]'); // dobbelttrykk skal ikke hoppe over motspillerens replikk
          continue;
        }
        if (s.listen && s.text.includes('Lytt til')) await snap(p, '06-lytter');
        if (s.listen && s.text.includes('Se opp')) await snap(p, '07-tilskuer-scene');
        if (s.form) {
          if (i === 0 && !paused && s.text.includes('beste skuespiller')) {
            paused = true;
            await p.click('[data-act=pause]');
            await pages[2].waitForFunction(() => document.getElementById('banner').textContent.includes('pause'));
            await p.click('[data-act=resume]');
            await p.waitForSelector('[data-act=pause]');
          }
          const panels = await p.$$('.q');
          for (let k = 0; k < panels.length; k++) {
            await p.locator('.q').nth(k).locator('.opt').nth((i + k + rounds) % 3).click();
          }
          await snap(p, s.text.includes('beste skuespiller') ? '08-gjetting-tilskuer' : '08b-gjetting-skuespiller');
          await p.click('[data-act=submit]');
          continue;
        }
        if (s.text.includes('Svaret ditt er registrert')) await snap(p, '09-kvittering');
        if (s.reveal) await snap(p, i === 0 ? '10-avsloring-vert' : '10b-avsloring');
        if (s.reveal && s.nextRound) {
          let all = true;
          for (const q of pages) if (!(await q.$('.reveal'))) all = false;
          if (all) { rounds++; await p.click('[data-act=nextRound]'); }
        }
      }
      if (finished === pages.length) break;
      await new Promise(r => setTimeout(r, 250));
    }
    if (!(await pages[0].$('[data-act=again]'))) fail('økten kom ikke til sluttstilling');
    await snap(pages[3], '11-sluttstilling');
    const read = (p) => p.$$eval('table.score tbody tr', rows => rows.map(r => [...r.children].map(c => c.textContent)));
    const table = await read(pages[0]);
    console.log('Runder spilt (inkl. øving):', rounds);
    console.log('Sluttstilling:', JSON.stringify(table));
    if (rounds !== 6) fail('forventet øvingsrunde + 5 runder, fikk ' + rounds);
    for (const r of table) if (+r[1] + +r[2] !== +r[3]) fail('sum stemmer ikke for ' + r[0]);
    for (const p of pages) {
      if (JSON.stringify(await read(p)) !== JSON.stringify(table)) fail('ulik sluttstilling på telefonene');
    }
    // Større tekst skal ikke gi vannrett rulling
    for (let k = 0; k < 4; k++) await pages[1].click('[data-act=textUp]');
    const overflow = await pages[1].evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    if (overflow) fail('vannrett rulling med stor tekst');
    await snap(pages[1], '12-stor-tekst');
    if (errors.length) fail('nettleserfeil: ' + errors.join(' | '));
  } catch (e) {
    fail(e.stack);
  } finally {
    await browser.close();
    php.kill();
  }
  console.log(failed ? 'E2E FEILET' : 'E2E OK');
  process.exit(failed ? 1 : 0);
})();
