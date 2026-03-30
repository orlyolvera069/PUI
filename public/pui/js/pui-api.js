const API_BASE = "/api/pui";

const PuiApi = (() => {
  const TOKEN_KEY = "pui.jwt";

  function getToken() {
    return localStorage.getItem(TOKEN_KEY) || "";
  }

  function setToken(token) {
    if (!token) return;
    localStorage.setItem(TOKEN_KEY, token);
  }

  function clearToken() {
    localStorage.removeItem(TOKEN_KEY);
  }

  async function request(path, options = {}, requiresAuth = true) {
    const token = getToken();
    const headers = {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
      ...(options.headers || {}),
    };
    const endpoint = `${API_BASE}${path}`;
    const response = await fetch(endpoint, {
      ...options,
      headers,
    });

    const text = await response.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch (e) {
      data = { raw: text };
    }

    return { ok: response.ok, status: response.status, data, endpoint, requiresAuth };
  }

  async function getModo() {
    return request("/salud", { method: "GET" }, false);
  }

  async function login(usuario, clave) {
    const res = await request(
      "/login",
      {
        method: "POST",
        body: JSON.stringify({ usuario, clave }),
      },
      false
    );

    if (res.ok && res.data && res.data.token) {
      setToken(res.data.token);
    }
    return res;
  }

  async function consultarPersona(curp) {
    return request(`/persona/${encodeURIComponent(curp)}`, { method: "GET" }, true);
  }

  async function activarReportePrueba(payload) {
    return request(
      "/activar-reporte-prueba",
      {
        method: "POST",
        body: JSON.stringify(payload),
      },
      true
    );
  }

  async function desactivarReporte(id) {
    return request(
      "/desactivar-reporte",
      {
        method: "POST",
        body: JSON.stringify({ id }),
      },
      true
    );
  }

  return {
    API_BASE,
    getToken,
    setToken,
    clearToken,
    getModo,
    login,
    consultarPersona,
    activarReportePrueba,
    desactivarReporte,
  };
})();
