export async function getJson(request, path, expectedStatus = 200) {
  const response = await request.get(path);
  if (response.status() !== expectedStatus) {
    throw new Error(`GET ${path} devolvio ${response.status()}, esperado ${expectedStatus}`);
  }

  try {
    return await response.json();
  } catch (error) {
    throw new Error(`GET ${path} no devolvio JSON valido: ${error.message}`);
  }
}
