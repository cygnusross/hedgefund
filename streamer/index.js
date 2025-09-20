require('dotenv').config();

const { LightstreamerClient, Subscription } = require('lightstreamer-client-node');
const Redis = require('ioredis');
const pino = require('pino');

const log = pino({ level: process.env.LOG_LEVEL || 'info' });
const redis = new Redis(process.env.REDIS_URL); // e.g. redis://127.0.0.1:6379

let lsClient;
let priceSub;
let currentSession = null;

// Helper to (re)connect Lightstreamer
async function connectLS(session) {
  if (lsClient) {
    try { lsClient.unsubscribe(priceSub); } catch (_) {}
    try { lsClient.disconnect(); } catch (_) {}
  }

  lsClient = new LightstreamerClient(session.lsEndpoint);
  lsClient.connectionDetails.setUser(session.accountId);
  // IG requires "CST-<cst>|XST-<xst>" as password
  lsClient.connectionDetails.setPassword(`CST-${session.cst}|XST-${session.xst}`);

  lsClient.addListener({
    onStatusChange: status => log.info({ status }, 'Lightstreamer status'),
    onServerError: (code, msg) => log.error({ code, msg }, 'LS server error'),
  });

  lsClient.connect();

  // Build items & fields
  const epics = await redis.smembers('ig:watchlist'); // e.g. ["CS.D.EURUSD.TODAY.IP","CS.D.USDJPY.TODAY.IP"]
  const items = epics.map(epic => `MARKET:${epic}`);
  // Common IG price fields (keep it small first)
  const fields = ['BID', 'OFFER', 'UPDATE_TIME', 'MARKET_STATE'];

  // NOTE: IG wants MERGE mode for prices
  priceSub = new Subscription('MERGE', items, fields);
  priceSub.addListener({
    onSubscription:   () => log.info({ count: items.length }, 'Subscribed to prices'),
    onSubscriptionError: (code, message) => log.error({ code, message }, 'Sub error'),
    onItemUpdate: update => {
      const item = update.getItemName();           // e.g. "MARKET:CS.D.EURUSD.TODAY.IP"
      const epic = item.split(':')[1];
      const payload = {};
      update.forEachChangedField((fname, _, val) => { payload[fname] = val; });
      // Publish as a compact JSON to Redis pub/sub and a hash for last value
      redis.publish(`ticks:${epic}`, JSON.stringify(payload));
      redis.hset(`ticks:last:${epic}`, payload);
    }
  });

  lsClient.subscribe(priceSub);
}

// Poll for session changes (or you can use Redis keyspace notifications)
async function watchSession() {
  // initial load
  const raw = await redis.get('ig:session');
  if (!raw) {
    log.warn('No ig:session found in Redis yet. Waiting...');
  } else {
    currentSession = JSON.parse(raw);
    await connectLS(currentSession);
  }

  // simple poll every 30s for refreshed tokens
  setInterval(async () => {
    const raw2 = await redis.get('ig:session');
    if (!raw2) return;
    const s = JSON.parse(raw2);
    if (!currentSession || s.cst !== currentSession.cst || s.xst !== currentSession.xst || s.lsEndpoint !== currentSession.lsEndpoint) {
      log.info('Detected new IG session tokens. Reconnecting Lightstreamer…');
      currentSession = s;
      await connectLS(currentSession);
    }
  }, 30000);
}

watchSession().catch(err => {
  log.error(err, 'Failed to start watcher');
  process.exit(1);
});
