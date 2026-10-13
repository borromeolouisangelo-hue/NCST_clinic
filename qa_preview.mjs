const BASE = 'http://localhost/school-clinic-laravel/public';
const TEMP = 'C:/Users/Louis/AppData/Local/Temp/consultqa';

export default async function run(page, ui) {
  const out = {};

  await page.getByLabel('Username or Email').fill('qa_browser');
  await page.getByLabel('Password').fill('secret123');
  await page.getByRole('button', { name: 'Sign In' }).click();
  await page.waitForURL(/dashboard/, { timeout: 15000 }).catch(() => {});

  await page.goto(BASE + '/staff/consultations', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await page.click('button[data-bs-target="#consultationModal"]');
  await page.waitForTimeout(700);

  await page.setInputFiles('#diagnosisImage', TEMP + '/preview400x240.png');
  await page.waitForTimeout(400);

  out.preview = await page.evaluate(() => {
    const wrap = document.querySelector('#diagnosisImage').parentElement.querySelector('.consultation-image-preview');
    const img = wrap.querySelector('img');
    const rect = img.getBoundingClientRect();
    return {
      visible: !wrap.classList.contains('d-none'),
      filename: wrap.querySelector('div').textContent,
      natural: { w: img.naturalWidth, h: img.naturalHeight },
      rendered: { w: Math.round(rect.width), h: Math.round(rect.height) },
      naturalRatio: +(img.naturalWidth / img.naturalHeight).toFixed(3),
      renderedRatio: +(rect.width / rect.height).toFixed(3),
      withinContainer: rect.width <= wrap.getBoundingClientRect().width + 1,
      modalWidth: Math.round(document.querySelector('.modal-content').getBoundingClientRect().width),
      modalScrollHeight: document.querySelector('.modal-dialog').scrollHeight,
      clientHeight: document.querySelector('.modal-content').clientHeight
    };
  });

  await page.screenshot({ path: 'C:/xampp/htdocs/school-clinic-laravel/qa_preview_check.png' });

  // Scroll the preview into view and capture just the preview region.
  await page.evaluate(() => {
    document.querySelector('#diagnosisImage').scrollIntoView({ block: 'center' });
  });
  await page.waitForTimeout(300);
  const preview = page.locator('#diagnosisImage ~ .consultation-image-preview, .consultation-image-preview').first();
  await page.screenshot({ path: 'C:/xampp/htdocs/school-clinic-laravel/qa_preview_closeup.png' });
  return out;
}
