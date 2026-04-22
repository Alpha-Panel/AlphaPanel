import threading
import time

from installer.log_queue import LogQueue


def test_put_and_stream_yields_all_messages():
    q = LogQueue()
    q.put({"type": "line", "text": "hello"})
    q.put({"type": "line", "text": "world"})
    q.close()
    collected = list(q.stream())
    assert collected == [
        {"type": "line", "text": "hello"},
        {"type": "line", "text": "world"},
    ]


def test_stream_yields_from_concurrent_producer():
    q = LogQueue()

    def producer():
        for i in range(5):
            q.put({"type": "line", "text": f"msg-{i}"})
            time.sleep(0.01)
        q.close()

    threading.Thread(target=producer).start()

    collected = []
    for item in q.stream():
        collected.append(item)
    assert len(collected) == 5
    assert collected[0]["text"] == "msg-0"
    assert collected[-1]["text"] == "msg-4"


def test_put_after_close_raises():
    q = LogQueue()
    q.close()
    try:
        q.put({"type": "line", "text": "x"})
        assert False, "expected RuntimeError"
    except RuntimeError:
        pass
