let snapshot = {
  pendingRequests: 0,
  retryingRequests: 0,
};

const listeners = new Set();

function emit() {
  listeners.forEach((listener) => listener());
}

function updateSnapshot(patch) {
  snapshot = {
    ...snapshot,
    ...patch,
  };
  emit();
}

export function subscribe(listener) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function getApiStatusSnapshot() {
  return snapshot;
}

export function startApiRequest() {
  updateSnapshot({ pendingRequests: snapshot.pendingRequests + 1 });
}

export function finishApiRequest() {
  updateSnapshot({ pendingRequests: Math.max(0, snapshot.pendingRequests - 1) });
}

export function startApiRetry() {
  updateSnapshot({ retryingRequests: snapshot.retryingRequests + 1 });
}

export function finishApiRetry() {
  updateSnapshot({ retryingRequests: Math.max(0, snapshot.retryingRequests - 1) });
}