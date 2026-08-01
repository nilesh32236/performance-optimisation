#!/bin/bash
sed -i 's/Fixed by swapping `jest.runAllTimers()` with controlled `jest.advanceTimersByTime(interval)` enclosed in `act()`, and appending empty `await act( async () => {} )`/Fixed by consistently using controlled `jest.advanceTimersByTime(interval)` enclosed in `act()` instead of `jest.runAllTimers()`, and appending empty `await act( async () => {} )`/' .jules/inspector.md
