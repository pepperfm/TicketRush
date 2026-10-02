import http from 'k6/http';
import { check } from 'k6';

const baseUrl = __ENV.BASE_URL || 'http://localhost:8080';
const eventId = __ENV.EVENT_ID || '1';
const key = `k6-idempotency-${Date.now()}`;

let expectedReservationId = null;

export const options = {
  vus: 1,
  iterations: 20,
  thresholds: {
    checks: ['rate==1'],
    http_req_failed: ['rate==0'],
  },
};

export default function () {
  const response = http.post(
    `${baseUrl}/api/events/${eventId}/reservations`,
    null,
    {
      headers: {
        Accept: 'application/json',
        'Idempotency-Key': key,
      },
    },
  );

  const body = response.json();
  const reservationId = body?.data?.id;

  if (expectedReservationId === null) {
    expectedReservationId = reservationId;
  }

  check(response, {
    'request accepted': (r) => r.status === 201,
    'same reservation returned': () => reservationId === expectedReservationId,
  });
}
