const BASE = 'http://localhost/school-clinic-laravel/public';
const TEMP = 'C:/Users/Louis/AppData/Local/Temp/consultqa';

export default async function run(page, ui) {
  const out = { steps: [] };

  // Close whatever SweetAlert popup is open (dismissals + submit confirm);
  // force-click so overlay animation can never stall actionability checks.
  async function closePopup() {
    try {
      const btn = page.locator('.swal2-confirm:visible').first();
      await btn.waitFor({ state: 'visible', timeout: 8000 });
      await btn.click({ force: true, timeout: 8000 });
      return true;
    } catch (e) {
      return false;
    }
  }

  // Login as the QA staff account.
  await page.getByLabel('Username or Email').fill('qa_browser');
  await page.getByLabel('Password').fill('secret123');
  await page.getByRole('button', { name: 'Sign In' }).click();
  await page.waitForURL(/dashboard/, { timeout: 10000 }).catch(() => {});

  // Open the New Consultation modal.
  await page.goto(BASE + '/staff/consultations', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(600);
  await page.click('button[data-bs-target="#consultationModal"]');
  await page.waitForTimeout(600);

  // Attachments inputs present below each textarea?
  out.form = await page.evaluate(() => {
    const after = (textareaName) => {
      const ta = document.querySelector(`textarea[name="${textareaName}"]`);
      const wrap = ta ? ta.closest('.mb-3') : null;
      const input = wrap ? wrap.querySelector('input[type="file"]') : null;
      return input ? {
        id: input.id, name: input.name, accept: input.accept,
        label: wrap.querySelector('label[for="' + input.id + '"]').textContent.trim().replace(/\s+/g, ' '),
        previewStyle: wrap.querySelector('.consultation-image-preview img')?.getAttribute('style') || null
      } : 'MISSING';
    };
    return { diagnosis: after('diagnosis'), treatment: after('treatment') };
  });

  // Fill the form: pick the first patient from the datalist.
  const patient = await page.evaluate(() => {
    const opt = document.querySelector('#consultationPatientSuggestions option');
    const uid = opt ? opt.getAttribute('data-user-id') : null;
    if (uid) document.getElementById('consultationPatientId').value = uid;
    if (opt) document.querySelector('[data-patient-combobox]').value = opt.value;
    return uid ? opt.value : null;
  });
  out.patient = patient;
  await page.fill('textarea[name="complaint"]', 'QA attachment check');
  await page.fill('textarea[name="diagnosis"]', 'Sample diagnosis');
  await page.fill('textarea[name="treatment"]', 'Sample treatment');

  // 1. Valid PNG on diagnosis -> filename + preview appear.
  await page.setInputFiles('#diagnosisImage', TEMP + '/diagnosis.png');
  await page.waitForTimeout(300);
  out.validPreview = await page.evaluate(() => {
    const wrap = document.querySelector('#diagnosisImage').parentElement.querySelector('.consultation-image-preview');
    const img = wrap.querySelector('img');
    return {
      visible: !wrap.classList.contains('d-none'),
      filename: wrap.querySelector('div').textContent,
      srcIsBlob: (img.getAttribute('src') || '').startsWith('blob:'),
      style: img.getAttribute('style')
    };
  });

  // 2. Text file on treatment -> client-side rejection + warning popup.
  await page.setInputFiles('#treatmentImage', TEMP + '/notes.txt');
  await page.waitForTimeout(400);
  out.txtRejected = await page.evaluate(() => {
    const pop = document.querySelector('.swal2-popup');
    const input = document.getElementById('treatmentImage');
    return {
      popupShown: !!pop,
      popupText: pop ? pop.innerText.replace(/\s+/g, ' ').trim() : null,
      inputCleared: input.value === ''
    };
  });
  await closePopup();
  await page.waitForTimeout(300);

  // 3. Oversized PNG -> size rejection.
  await page.setInputFiles('#treatmentImage', TEMP + '/huge.png');
  await page.waitForTimeout(500);
  out.oversizedRejected = await page.evaluate(() => {
    const pop = document.querySelector('.swal2-popup');
    return { popupShown: !!pop, popupText: pop ? pop.innerText.replace(/\s+/g, ' ').trim() : null };
  });
  await closePopup();
  await page.waitForTimeout(300);

  // 4. Valid PNG on treatment -> preview appears; screenshot the modal.
  await page.setInputFiles('#treatmentImage', TEMP + '/treatment.png');
  await page.waitForTimeout(300);
  out.treatmentPreview = await page.evaluate(() => {
    const wrap = document.querySelector('#treatmentImage').parentElement.querySelector('.consultation-image-preview');
    return { visible: !wrap.classList.contains('d-none'), filename: wrap.querySelector('div').textContent };
  });
  await page.screenshot({ path: 'C:/xampp/htdocs/school-clinic-laravel/qa_modal_form.png' });

  // 5. Submit (data-swal-add opens a SweetAlert confirm first).
  await page.click('button[data-swal-add="consultation"]');
  out.submitConfirmed = await closePopup();
  await page.waitForFunction(() => {
    const row = document.querySelector('#consultationTable tbody tr');
    const td = row ? row.querySelectorAll('td')[2] : null;
    return !!td && td.textContent.includes('QA attachment check');
  }, { timeout: 15000 }).catch(() => { out.rowWait = 'TIMEOUT - row never appeared'; });
  await page.waitForTimeout(500);

  // 6. The saved row shows both attachment buttons; fetch them with the session.
  out.savedRow = await page.evaluate(() => {
    const row = document.querySelector('#consultationTable tbody tr');
    const links = row ? [...row.querySelectorAll('a[href*="/attachments/"]')].map(a => ({ title: a.title, href: a.href })) : [];
    return { firstRowComplaint: row ? row.querySelectorAll('td')[2]?.textContent.trim() : null, links };
  });
  out.attachmentFetch = [];
  for (const link of out.savedRow.links) {
    const resp = await page.request.get(link.href);
    out.attachmentFetch.push({ title: link.title, status: resp.status(), contentType: resp.headers()['content-type'] });
  }
  await page.screenshot({ path: 'C:/xampp/htdocs/school-clinic-laravel/qa_saved_row.png' });

  return out;
}
