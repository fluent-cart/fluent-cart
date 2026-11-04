/**
 * Runs report requests a few at a time, in the order they were added, so a page with many sections does not hit
 * a small host with all of them at once. clear() drops the requests still waiting, for example after a filter change.
 */
export const createRequestQueue = (maxParallel = 3) => {
  let waiting = [];
  let running = 0;

  const pump = () => {
    while (running < maxParallel && waiting.length) {
      const task = waiting.shift();
      running++;
      // Started right away; a task that throws still frees its slot
      let started;
      try {
        started = task();
      } catch (error) {
        started = null;
      }
      Promise.resolve(started)
        .catch(() => {})
        .finally(() => {
          running--;
          pump();
        });
    }
  };

  return {
    // task returns a promise; first puts it at the head of the line
    add(task, first = false) {
      first ? waiting.unshift(task) : waiting.push(task);
      pump();
    },
    clear() {
      waiting = [];
    },
  };
};
