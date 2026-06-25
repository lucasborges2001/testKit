export async function loginByForm(page, options) {
  const {
    path,
    email,
    password,
    expectedUrl,
    emailLabel = "Email",
    passwordLabel = "Contraseña",
    submitName = "Ingresar",
  } = options;

  if (!path) {
    throw new Error("loginByForm requiere path");
  }
  if (!email || !password) {
    throw new Error("loginByForm requiere email y password");
  }

  await page.goto(path);
  await page.getByLabel(emailLabel).fill(email);
  await page.getByLabel(passwordLabel).fill(password);
  await page.getByRole("button", { name: submitName }).click();

  if (expectedUrl) {
    await page.waitForURL(expectedUrl);
  }
}
