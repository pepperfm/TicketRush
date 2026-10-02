import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const baseUrl = __ENV.BASE_URL || 'http://localhost:8080';
const eventId = __ENV.EVENT_ID || '1';

const soldOut = new Counter('ticketrush_sold_out');
const unexpected = new Counter('ticketrush_unexpected_status');

export const options = {
  scenarios: {
    reservations: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.RATE || 100),
      timeUnit: '1s',
      duration: __ENV.DURATION || '30s',
      preAllocatedVUs: Number(__ENV.PRE_ALLOCATED_VUS || 100),
      maxVUs: Number(__ENV.MAX_VUS || 1000),
    },
  },
  thresholds: {
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<250', 'p(99)<750'],
  },
};

export default function () {
  const key = `k6-${__VU}-${__ITER}-${Date.now()}-${Math.random()}`;

  const response = http.post(
    `${baseUrl}/api/events/${eventId}/reservations`,
    null,
    {
      headers: {
        Accept: 'application/json',
        'Idempotency-Key': key,
      },
      tags: {
        endpoint: 'reserve-ticket',
      },
    },
  );

  if (response.status === 409) {
    soldOut.add(1);
  } else if (response.status !== 201) {
    unexpected.add(1);
  }

  check(response, {
    'reservation created': (r) => r.status === 201,
  });
}
