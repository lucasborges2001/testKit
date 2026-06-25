export async function run({ page, request, expect, config }) {
  await page.goto('/');

  await expect(page.getByRole('heading', { name: 'TestKit Browser Smoke' })).toBeVisible();
  await expect(page.getByTestId('status')).toHaveText('runner-ok');
  await expect(page.getByTestId('base-url')).toContainText(new URL(config.baseURL).host);

  const response = await request.get('/health.json');
  expect(response.ok()).toBeTruthy();

  const payload = await response.json();
  expect(payload.ok).toBe(true);
  expect(payload.fixture).toBe('browser-runner-smoke');
}
