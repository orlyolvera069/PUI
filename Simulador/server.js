"use strict";

/**
 * server.js — Simulador PUI federal
 *
 * Grupo A: endpoints que la API_PUI consume (sin Basic Auth)
 *   POST /login
 *   POST /notificar-coincidencia
 *   POST /busqueda-finalizada
 *
 * Grupo B: administración (Basic Auth requerido)
 *   GET  /admin/empresas           — lista las empresas configuradas
 *   GET  /admin/reportes           — todos los reportes
 *   POST /admin/activar-reporte    — activa y llama a la API_PUI
 *   POST /admin/desactivar-reporte — desactiva y llama a la API_PUI
 *   GET  /admin/logs?last=50       — últimas N líneas del log (no se loguea a sí mismo)
 *   GET  /admin/config             — lee data/config.json
 *   PUT  /admin/config             — escribe data/config.json
 *
 * Archivos estáticos en /public/ protegidos con Basic Auth nativa del browser.
 * Las rutas /admin/* devuelven 401 SIN WWW-Authenticate para evitar que el
 * browser muestre el diálogo de nuevo después de haber autenticado la página.
 */

import "dotenv/config";
import express from "express";
import jwt from "jsonwebtoken";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

// ── Paths ────────────────────────────────────────────────────────────────────

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR  = path.join(__dirname, "data");
const LOGS_DIR  = path.join(__dirname, "logs");
const LOG_FILE  = path.join(LOGS_DIR, "requests.log");

fs.mkdirSync(DATA_DIR, { recursive: true });
fs.mkdirSync(LOGS_DIR, { recursive: true });

const REPORTES_FILE = path.join(DATA_DIR, "reportes.json");
const CONFIG_FILE   = path.join(DATA_DIR, "config.json");

// ── Configuración desde .env ─────────────────────────────────────────────────

const {
  UI_USER  = "admin",
  UI_PASSWORD = "",
  JWT_SECRET  = "",
  PUI_CLAVE_ESPERADA = "",
  PORT = "3000",

  // Empresa 1
  EMPRESA_1_NOMBRE   = "Empresa 1",
  EMPRESA_1_API_URL  = "",
  EMPRESA_1_API_CLAVE = "",

  // Empresa 2
  EMPRESA_2_NOMBRE   = "Empresa 2",
  EMPRESA_2_API_URL  = "",
  EMPRESA_2_API_CLAVE = "",
} = process.env;

if (!JWT_SECRET)          console.warn("[WARN] JWT_SECRET no configurado en .env");
if (!UI_PASSWORD)         console.warn("[WARN] UI_PASSWORD no configurado en .env");
if (!PUI_CLAVE_ESPERADA)  console.warn("[WARN] PUI_CLAVE_ESPERADA no configurado en .env");
if (!EMPRESA_1_API_URL)   console.warn("[WARN] EMPRESA_1_API_URL no configurado en .env");
if (!EMPRESA_2_API_URL)   console.warn("[WARN] EMPRESA_2_API_URL no configurado en .env");

// Mapa de empresas indexado por clave corta
const EMPRESAS = {
  empresa1: { nombre: EMPRESA_1_NOMBRE, api_url: EMPRESA_1_API_URL, api_clave: EMPRESA_1_API_CLAVE },
  empresa2: { nombre: EMPRESA_2_NOMBRE, api_url: EMPRESA_2_API_URL, api_clave: EMPRESA_2_API_CLAVE },
};

// ── Helpers de persistencia ──────────────────────────────────────────────────

function readJSON(filePath, defaultValue) {
  try {
    return JSON.parse(fs.readFileSync(filePath, "utf8"));
  } catch {
    return defaultValue;
  }
}

function writeJSON(filePath, data) {
  fs.writeFileSync(filePath, JSON.stringify(data, null, 2) + "\n", "utf8");
}

// ── Logging de solicitudes ───────────────────────────────────────────────────

function bodySummary(body) {
  if (!body || typeof body !== "object") return "";
  const copy = { ...body };
  if (copy.clave)    copy.clave    = "***";
  if (copy.password) copy.password = "***";

  return Object.entries(copy)
    .filter(([, v]) => v !== null && v !== undefined)
    .slice(0, 6)
    .map(([k, v]) => {
      const str = typeof v === "object" ? JSON.stringify(v) : String(v);
      return `${k}=${str.length > 40 ? str.slice(0, 40) + "…" : str}`;
    })
    .join(", ");
}

function logRequest(req, status) {
  // No loguear las consultas de polling del propio log
  if (req.method === "GET" && req.path === "/admin/logs") return;

  const entry = {
    ts:           new Date().toISOString(),
    method:       req.method,
    path:         req.path,
    ip:           req.ip || req.socket?.remoteAddress || "-",
    status,
    body_summary: bodySummary(req.body),
  };
  fs.appendFileSync(LOG_FILE, JSON.stringify(entry) + "\n", "utf8");
}

function requestLogger(req, res, next) {
  res.on("finish", () => logRequest(req, res.statusCode));
  next();
}

// ── Basic Auth ───────────────────────────────────────────────────────────────

/**
 * basicAuthStatic: para archivos estáticos.
 * Envía WWW-Authenticate para que el browser muestre su diálogo nativo UNA VEZ.
 */
function basicAuthStatic(req, res, next) {
  const authHeader = req.headers.authorization ?? "";
  if (authHeader.startsWith("Basic ")) {
    const decoded    = Buffer.from(authHeader.slice(6), "base64").toString("utf8");
    const colonIndex = decoded.indexOf(":");
    const user       = decoded.slice(0, colonIndex);
    const pass       = decoded.slice(colonIndex + 1);
    if (user === UI_USER && pass === UI_PASSWORD) return next();
  }
  res.set("WWW-Authenticate", 'Basic realm="PUI Simulador"');
  return res.status(401).send("Autenticación requerida.");
}

/**
 * basicAuthApi: para rutas /admin/*.
 * NO envía WWW-Authenticate — el browser ya tiene las credenciales en caché
 * desde basicAuthStatic. Esto evita el bucle de diálogos.
 */
function basicAuthApi(req, res, next) {
  const authHeader = req.headers.authorization ?? "";
  if (authHeader.startsWith("Basic ")) {
    const decoded    = Buffer.from(authHeader.slice(6), "base64").toString("utf8");
    const colonIndex = decoded.indexOf(":");
    const user       = decoded.slice(0, colonIndex);
    const pass       = decoded.slice(colonIndex + 1);
    if (user === UI_USER && pass === UI_PASSWORD) return next();
  }
  // Sin WWW-Authenticate: el fetch del browser no abre diálogo, solo devuelve 401
  return res.status(401).json({ error: "Autenticación requerida." });
}

// ── Obtener token de la API_PUI por empresa ───────────────────────────────────

async function obtenerTokenApiPui(empresaKey) {
  const empresa = EMPRESAS[empresaKey];
  if (!empresa) throw new Error(`Empresa desconocida: '${empresaKey}'.`);
  if (!empresa.api_url) throw new Error(`API_URL no configurada para '${empresa.nombre}'.`);

  const res = await fetch(`${empresa.api_url}/login`, {
    method:  "POST",
    headers: { "Content-Type": "application/json" },
    body:    JSON.stringify({ usuario: "PUI", clave: empresa.api_clave }),
  });
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`Login en ${empresa.nombre} falló (${res.status}): ${text}`);
  }
  const data = await res.json();
  // La API_PUI devuelve el token bajo la clave "token" (LoginResponse.token)
  return data.token ?? data.access_token;
}

// ── App Express ───────────────────────────────────────────────────────────────

const app = express();
app.use(express.json());
app.use(requestLogger);

// ── GRUPO A: Endpoints que la API_PUI consume (sin Basic Auth) ────────────────

/**
 * POST /login
 * La API_PUI envía { usuario: "PUI", clave: "..." }
 */
app.post("/login", (req, res) => {
  const { usuario, institucion_id, clave } = req.body ?? {};
  // La API_PUI envía "institucion_id" (RFC de la institución), no la cadena "PUI".
  // La validación de identidad se hace solo por clave; el sujeto puede ser cualquier valor no vacío.
  const sujeto = usuario ?? institucion_id;
  if (!sujeto || clave !== PUI_CLAVE_ESPERADA) {
    return res.status(401).json({ error: "Credenciales inválidas." });
  }
  const token = jwt.sign({ sub: sujeto, rol: "federal" }, JWT_SECRET, {
    expiresIn: "1h",
    algorithm: "HS256",
  });
  // Devolver ambos campos para compatibilidad:
  // - "token"        → lo que espera pui_client.py (Python)
  // - "access_token" → lo que usaba el simulador antes
  return res.json({ token, access_token: token, token_type: "bearer" });
});

/**
 * POST /notificar-coincidencia
 */
app.post("/notificar-coincidencia", (req, res) => {
  const authHeader = req.headers.authorization ?? "";
  if (!authHeader.startsWith("Bearer ")) {
    return res.status(401).json({ error: "Token requerido." });
  }
  try {
    jwt.verify(authHeader.slice(7), JWT_SECRET, { algorithms: ["HS256"] });
  } catch {
    return res.status(401).json({ error: "Token inválido o expirado." });
  }

  const { curp, id, fase_busqueda } = req.body ?? {};
  console.log(`[COINCIDENCIA] curp=${curp} id=${id} fase=${fase_busqueda}`);

  if (id) {
    const reportes = readJSON(REPORTES_FILE, []);
    const idx = reportes.findIndex((r) => r.id === id);
    if (idx !== -1) {
      reportes[idx].coincidencias_recibidas = (reportes[idx].coincidencias_recibidas ?? 0) + 1;
      writeJSON(REPORTES_FILE, reportes);
    }
  }

  return res.status(200).json({ message: "Coincidencia recibida." });
});

/**
 * POST /busqueda-finalizada
 */
app.post("/busqueda-finalizada", (req, res) => {
  const authHeader = req.headers.authorization ?? "";
  if (!authHeader.startsWith("Bearer ")) {
    return res.status(401).json({ error: "Token requerido." });
  }
  try {
    jwt.verify(authHeader.slice(7), JWT_SECRET, { algorithms: ["HS256"] });
  } catch {
    return res.status(401).json({ error: "Token inválido o expirado." });
  }

  const { id, curp } = req.body ?? {};
  console.log(`[BUSQUEDA_FINALIZADA] id=${id} curp=${curp}`);

  if (id) {
    const reportes = readJSON(REPORTES_FILE, []);
    const idx = reportes.findIndex((r) => r.id === id);
    if (idx !== -1) {
      reportes[idx].busqueda_finalizada = true;
      writeJSON(REPORTES_FILE, reportes);
    }
  }

  return res.status(200).json({ message: "Búsqueda finalizada registrada." });
});

// ── GRUPO B: Admin (Basic Auth de API, sin re-prompt) ────────────────────────

/**
 * GET /admin/empresas
 * Devuelve la lista de empresas configuradas (sin exponer claves).
 */
app.get("/admin/empresas", basicAuthApi, (req, res) => {
  const lista = Object.entries(EMPRESAS).map(([key, e]) => ({
    key,
    nombre:      e.nombre,
    api_url:     e.api_url,
    configurada: Boolean(e.api_url && e.api_clave),
  }));
  return res.json(lista);
});

/**
 * POST /admin/verificar-empresa
 * Body: { key }
 * Llama a GET /health de la API_PUI de la empresa indicada.
 * Devuelve 200 si responde, 502 si no es alcanzable o no está configurada.
 */
app.post("/admin/verificar-empresa", basicAuthApi, async (req, res) => {
  const { key } = req.body ?? {};
  const empresa = EMPRESAS[key];
  if (!empresa) {
    return res.status(400).json({ ok: false, error: `Empresa desconocida: '${key}'.` });
  }
  if (!empresa.api_url) {
    return res.status(400).json({ ok: false, error: `API_URL no configurada para '${empresa.nombre}'.` });
  }

  try {
    // Llamada pública al endpoint de salud de la API (GET, sin Authorization).
    const healthRes = await fetch(`${empresa.api_url}/salud`, {
      method: "GET",
      signal: AbortSignal.timeout(5000),
    });
    const body = await healthRes.json().catch(() => ({}));

    // Consideramos éxito solo si la API responde 200 y el JSON contiene { status: "ok" }.
    if (healthRes.status === 200 && body && body.status === "ok") {
      return res.json({ ok: true, message: `${empresa.nombre} responde — status: ${body.status}` });
    }

    // Respuesta inesperada desde la API de la empresa.
    return res.status(502).json({
      ok:    false,
      error: `${empresa.nombre} respondió HTTP ${healthRes.status} — body: ${JSON.stringify(body)}`,
      url:   `${empresa.api_url}/salud`,
      hint:  "Verifica que Apache/uvicorn estén levantados y que la URL sea correcta.",
    });
  } catch (err) {
    return res.status(502).json({
      ok:    false,
      error: `No se pudo conectar con ${empresa.nombre}: ${err.message}`,
      url:   `${empresa.api_url}/salud`,
      hint:  "Verifica que la URL sea alcanzable desde esta instancia.",
    });
  }
});

/**
 * GET /admin/reportes
 */
app.get("/admin/reportes", basicAuthApi, (req, res) => {
  const reportes = readJSON(REPORTES_FILE, []);
  return res.json(reportes);
});

/**
 * POST /admin/activar-reporte
 * Body: { empresa, id, curp, lugar_nacimiento, fecha_desaparicion? }
 */
app.post("/admin/activar-reporte", basicAuthApi, async (req, res) => {
  const { empresa, id, curp, lugar_nacimiento, fecha_desaparicion } = req.body ?? {};

  if (!empresa || !id || !curp || !lugar_nacimiento) {
    return res.status(400).json({ error: "empresa, id, curp y lugar_nacimiento son obligatorios." });
  }
  if (!EMPRESAS[empresa]) {
    return res.status(400).json({ error: `Empresa desconocida: '${empresa}'.` });
  }

  const reportes = readJSON(REPORTES_FILE, []);
  if (reportes.find((r) => r.id === id)) {
    return res.status(409).json({ error: `Ya existe un reporte con id='${id}'.` });
  }

  let token;
  try {
    token = await obtenerTokenApiPui(empresa);
  } catch (err) {
    return res.status(502).json({ error: `No se pudo autenticar con la API_PUI: ${err.message}` });
  }

  const payload = { id, curp, lugar_nacimiento };
  if (fecha_desaparicion) payload.fecha_desaparicion = fecha_desaparicion;

  // Persistir el reporte ANTES de POST /activar-reporte: la API_PUI puede llamar en ese mismo
  // request a POST /notificar-coincidencia y POST /busqueda-finalizada hacia este simulador.
  // Si el registro aún no existe, esos handlers no actualizan coincidencias ni busqueda_finalizada.
  const nuevoReporte = {
    empresa,
    empresa_nombre:          EMPRESAS[empresa].nombre,
    id,
    curp,
    lugar_nacimiento,
    fecha_desaparicion:      fecha_desaparicion ?? null,
    activo:                  true,
    fecha_envio:             new Date().toISOString(),
    coincidencias_recibidas: 0,
    busqueda_finalizada:     false,
  };
  reportes.push(nuevoReporte);
  writeJSON(REPORTES_FILE, reportes);

  function quitarReporteSiFallo() {
    const idx = reportes.findIndex((r) => r.id === id);
    if (idx !== -1) {
      reportes.splice(idx, 1);
      writeJSON(REPORTES_FILE, reportes);
    }
  }

  let apiRes;
  try {
    apiRes = await fetch(`${EMPRESAS[empresa].api_url}/activar-reporte`, {
      method:  "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body:    JSON.stringify(payload),
    });
  } catch (err) {
    quitarReporteSiFallo();
    return res.status(502).json({ error: `Error de red al llamar API_PUI: ${err.message}` });
  }

  const apiBody = await apiRes.json().catch(() => ({}));
  if (!apiRes.ok) {
    quitarReporteSiFallo();
    return res.status(apiRes.status).json(apiBody);
  }

  return res.status(200).json({ message: "Reporte activado correctamente.", api_response: apiBody });
});

/**
 * POST /admin/desactivar-reporte
 * Body: { id }
 */
app.post("/admin/desactivar-reporte", basicAuthApi, async (req, res) => {
  const { id } = req.body ?? {};
  if (!id) return res.status(400).json({ error: "id es obligatorio." });

  const reportes = readJSON(REPORTES_FILE, []);
  const idx = reportes.findIndex((r) => r.id === id);
  if (idx === -1) return res.status(404).json({ error: `No se encontró reporte con id='${id}'.` });

  const empresa = reportes[idx].empresa;
  let token;
  try {
    token = await obtenerTokenApiPui(empresa);
  } catch (err) {
    return res.status(502).json({ error: `No se pudo autenticar con la API_PUI: ${err.message}` });
  }

  let apiRes;
  try {
    apiRes = await fetch(`${EMPRESAS[empresa].api_url}/desactivar-reporte`, {
      method:  "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body:    JSON.stringify({ id }),
    });
  } catch (err) {
    return res.status(502).json({ error: `Error de red al llamar API_PUI: ${err.message}` });
  }

  const apiBody = await apiRes.json().catch(() => ({}));
  if (!apiRes.ok) return res.status(apiRes.status).json(apiBody);

  reportes[idx].activo = false;
  writeJSON(REPORTES_FILE, reportes);

  return res.status(200).json({ message: "Reporte desactivado correctamente.", api_response: apiBody });
});

/**
 * GET /admin/logs?last=50
 * No se loguea a sí mismo (filtrado en requestLogger).
 */
app.get("/admin/logs", basicAuthApi, (req, res) => {
  const last = Math.min(parseInt(req.query.last ?? "50", 10) || 50, 500);

  if (!fs.existsSync(LOG_FILE)) return res.json([]);

  const lines = fs
    .readFileSync(LOG_FILE, "utf8")
    .trim()
    .split("\n")
    .filter(Boolean)
    .slice(-last)
    .map((line) => {
      try   { return JSON.parse(line); }
      catch { return { ts: null, method: "?", path: line, status: null, body_summary: "" }; }
    });

  return res.json(lines.reverse()); // más reciente primero
});

/**
 * GET /admin/config
 */
app.get("/admin/config", basicAuthApi, (req, res) => {
  return res.json(readJSON(CONFIG_FILE, {}));
});

/**
 * PUT /admin/config
 */
app.put("/admin/config", basicAuthApi, (req, res) => {
  const updated = { ...readJSON(CONFIG_FILE, {}), ...(req.body ?? {}) };
  writeJSON(CONFIG_FILE, updated);
  return res.json(updated);
});

// ── Archivos estáticos (Basic Auth con browser dialog) ───────────────────────

app.use("/", basicAuthStatic, express.static(path.join(__dirname, "public")));

// ── Arranque ──────────────────────────────────────────────────────────────────

const port = parseInt(PORT, 10);
app.listen(port, () => {
  console.log(`[PUI Simulador] Escuchando en http://localhost:${port}`);
  console.log(`[PUI Simulador] Empresa 1: ${EMPRESA_1_NOMBRE} → ${EMPRESA_1_API_URL || "(sin URL)"}`);
  console.log(`[PUI Simulador] Empresa 2: ${EMPRESA_2_NOMBRE} → ${EMPRESA_2_API_URL || "(sin URL)"}`);
});
