const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

function uid() { return crypto.randomUUID(); }

function buildAuth(auth) {
  if (!auth) return undefined;
  if (auth.type === 'noauth') return { type: 'noauth' };
  if (auth.type === 'bearer') {
    const token = (auth.credentials && auth.credentials.token) ? auth.credentials.token : '';
    return { type: 'bearer', bearer: [{ key: 'token', value: token, type: 'string' }] };
  }
  return { type: auth.type };
}

function buildUrl(urlStr) {
  if (!urlStr) return { raw: '', host: [''], path: [''] };
  const raw = urlStr;
  let withoutProtocol = urlStr;
  let protocol = '';
  const protoMatch = urlStr.match(/^(https?):\/\//);
  if (protoMatch) {
    protocol = protoMatch[1];
    withoutProtocol = urlStr.slice(protoMatch[0].length);
  }
  const qIdx = withoutProtocol.indexOf('?');
  let pathPart = qIdx >= 0 ? withoutProtocol.slice(0, qIdx) : withoutProtocol;
  const queryStr = qIdx >= 0 ? withoutProtocol.slice(qIdx + 1) : '';
  const slashIdx = pathPart.indexOf('/');
  const hostStr = slashIdx >= 0 ? pathPart.slice(0, slashIdx) : pathPart;
  const pathStr = slashIdx >= 0 ? pathPart.slice(slashIdx) : '';

  const host = hostStr ? hostStr.split('.') : [''];
  const pathSegments = pathStr ? pathStr.split('/').filter((s, i) => i > 0 || s !== '').map(s => s || '') : [];

  const urlObj = { raw };
  if (protocol) urlObj.protocol = protocol;
  urlObj.host = host;
  if (pathSegments.length) urlObj.path = pathSegments;

  if (queryStr) {
    urlObj.query = queryStr.split('&').map(p => {
      const eqIdx = p.indexOf('=');
      const k = eqIdx >= 0 ? p.slice(0, eqIdx) : p;
      const v = eqIdx >= 0 ? p.slice(eqIdx + 1) : '';
      return { key: decodeURIComponent(k), value: v };
    });
  }
  return urlObj;
}

function buildHeaders(headers) {
  if (!headers) return [];
  if (Array.isArray(headers)) {
    return headers.map(h => ({ key: h.key, value: h.value, type: 'text' }));
  }
  return Object.entries(headers).map(([k, v]) => ({ key: k, value: v, type: 'text' }));
}

function buildBody(body) {
  if (!body) return undefined;
  if (body.type === 'json') {
    return { mode: 'raw', raw: body.content, options: { raw: { language: 'json' } } };
  }
  if (body.type === 'formdata') {
    return { mode: 'formdata', formdata: (body.content || []).map(i => ({ key: i.key, value: i.value, type: 'text' })) };
  }
  if (body.type === 'urlencoded') {
    return { mode: 'urlencoded', urlencoded: (body.content || []).map(i => ({ key: i.key, value: i.value })) };
  }
  return { mode: 'raw', raw: body.content || '' };
}

function buildScripts(scripts) {
  const pre = [], post = [];
  if (!scripts) return { pre, post };
  for (const s of scripts) {
    if (s.type === 'beforeRequest' || s.type === 'http:beforeRequest') pre.push(s.code);
    if (s.type === 'afterResponse' || s.type === 'http:afterResponse') post.push(s.code);
  }
  return { pre, post };
}

function buildRequest(yamlObj, name) {
  const req = {
    name: yamlObj.name || name,
    request: {
      method: yamlObj.method || 'GET',
      header: buildHeaders(yamlObj.headers),
      url: buildUrl(yamlObj.url),
    },
    response: [],
    _postman_id: uid()
  };
  const auth = buildAuth(yamlObj.auth);
  if (auth) req.request.auth = auth;
  const body = buildBody(yamlObj.body);
  if (body) req.request.body = body;
  const { pre, post } = buildScripts(yamlObj.scripts);
  if (pre.length || post.length) req.event = [];
  if (pre.length) req.event.push({ listen: 'prerequest', script: { type: 'text/javascript', exec: pre.join('\n').split('\n') } });
  if (post.length) req.event.push({ listen: 'test', script: { type: 'text/javascript', exec: post.join('\n').split('\n') } });
  return req;
}

const yaml = require('js-yaml');

const colRoot = 'postman/collections/E-commerce - Requirement 2';
const defYaml = yaml.load(fs.readFileSync(path.join(colRoot, '.resources/definition.yaml'), 'utf8'));

// Build variables
const variables = [];
if (defYaml.variables) {
  for (const [k, v] of Object.entries(defYaml.variables)) {
    variables.push({ key: k, value: String(v), type: 'default', enabled: true });
  }
}

// Auth folder
const authDef = yaml.load(fs.readFileSync(path.join(colRoot, 'Auth/.resources/definition.yaml'), 'utf8'));
const authFiles = [
  { file: 'Login as admin.request.yaml' },
  { file: 'Login as user.request.yaml' },
  { file: 'Register new customer.request.yaml' },
  { file: 'Logout customer.request.yaml' },
];
const authItems = authFiles.map(r => {
  const y = yaml.load(fs.readFileSync(path.join(colRoot, 'Auth', r.file), 'utf8'));
  return buildRequest(y, r.file.replace('.request.yaml', ''));
});

// Req2 folder
const req2Def = yaml.load(fs.readFileSync(path.join(colRoot, 'Requirement 2 - Warehouse integration (gRPC)/.resources/definition.yaml'), 'utf8'));

// Inventory subfolder
const inventoryFiles = [
  'show-1.request.yaml',
  'index.request.yaml',
  'Set stock to 5 (admin).request.yaml',
  'Read stock back from the Warehouse (5).request.yaml',
  'Set stock - as user (403).request.yaml',
  'Set stock - negative (422).request.yaml',
  'Set stock - unknown product (404).request.yaml',
  'v2 in_stock filter asks the Warehouse.request.yaml',
];
const inventoryItems = inventoryFiles.map(f => {
  const y = yaml.load(fs.readFileSync(path.join(colRoot, 'Requirement 2 - Warehouse integration (gRPC)/Inventory (stock lives in the Warehouse)', f), 'utf8'));
  return buildRequest(y, f.replace('.request.yaml', ''));
});

// Order reserves stock subfolder
const orderFiles = [
  'Order 2 units.request.yaml',
  'Order more than available (409).request.yaml',
  'Failed order did not touch stock (3).request.yaml',
];
const orderItems = orderFiles.map(f => {
  const y = yaml.load(fs.readFileSync(path.join(colRoot, 'Requirement 2 - Warehouse integration (gRPC)/Order reserves stock', f), 'utf8'));
  return buildRequest(y, f.replace('.request.yaml', ''));
});

// Cancel returns stock subfolder
const cancelFiles = [
  'Cancel the 2-unit order (admin).request.yaml',
  'Reopen a cancelled order (409).request.yaml',
  'Track the cancelled order.request.yaml',
];
const cancelItems = cancelFiles.map(f => {
  const y = yaml.load(fs.readFileSync(path.join(colRoot, 'Requirement 2 - Warehouse integration (gRPC)/Cancel returns stock', f), 'utf8'));
  return buildRequest(y, f.replace('.request.yaml', ''));
});

// Idempotency subfolder
const idempFiles = [
  'Order 1 unit with a new key.request.yaml',
  'Repeat the same request and key.request.yaml',
];
const idempItems = idempFiles.map(f => {
  const y = yaml.load(fs.readFileSync(path.join(colRoot, 'Requirement 2 - Warehouse integration (gRPC)/Idempotency-Key (no double ordering)', f), 'utf8'));
  return buildRequest(y, f.replace('.request.yaml', ''));
});

// New Request at root
const newReqYaml = yaml.load(fs.readFileSync(path.join(colRoot, 'New Request.request.yaml'), 'utf8'));
const newReqItem = buildRequest(newReqYaml, 'New Request');

const collection = {
  info: {
    _postman_id: uid(),
    name: 'E-commerce - Requirement 2',
    description: defYaml.description || '',
    schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'
  },
  item: [
    {
      name: 'Auth',
      description: authDef.description || '',
      item: authItems,
      _postman_id: uid()
    },
    {
      name: 'Requirement 2 - Warehouse integration (gRPC)',
      description: req2Def.description || '',
      item: [
        { name: 'Inventory (stock lives in the Warehouse)', item: inventoryItems, _postman_id: uid() },
        { name: 'Order reserves stock', item: orderItems, _postman_id: uid() },
        { name: 'Cancel returns stock', item: cancelItems, _postman_id: uid() },
        { name: 'Idempotency-Key (no double ordering)', item: idempItems, _postman_id: uid() }
      ],
      _postman_id: uid()
    },
    newReqItem
  ],
  variable: variables
};

const outPath = 'E-commerce - Requirement 2.postman_collection.json';
fs.writeFileSync(outPath, JSON.stringify(collection, null, 2), 'utf8');
console.log('Written to: ' + outPath);
console.log('Top-level items:', collection.item.length);
