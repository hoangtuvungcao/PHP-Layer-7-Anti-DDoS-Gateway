#!/usr/bin/env python3
"""Interactive tester for anti-DDoS layers (0-5).

Usage:
    python scripts/layer_tester.py
"""

import argparse
import json
import sys
import time
from typing import Dict, List, Optional

import requests

LAYERS = {
    "0": "Layer 0 – IP Reputation",
    "1": "Layer 1 – Traffic Filter",
    "2": "Layer 2 – Rate Limiter",
    "3": "Layer 3 – Browser Challenge",
    "4": "Layer 4 – Behaviour Score",
    "5": "Layer 5 – Session Hardening",
}

DEFAULT_HOST = "http://localhost/"


class LayerScenario:
    """Defines how to craft requests to trigger specific layers."""

    def __init__(self, name: str, host: str):
        self.name = name
        self.host = host.rstrip("/") + "/"
        self.session = requests.Session()

    def run(self, layer: str, attempts: int = 5) -> List[Dict[str, Optional[str]]]:
        handler = getattr(self, f"_test_layer_{layer}", None)
        if handler is None:
            raise ValueError(f"Layer {layer} is not supported")
        return handler(attempts)

    # Internal helpers -------------------------------------------------
    def _send_request(self, method: str = "GET", headers: Optional[Dict[str, str]] = None, data: Optional[Dict[str, str]] = None) -> requests.Response:
        headers = headers or {}
        response = self.session.request(method=method, url=self.host, headers=headers, data=data, timeout=10, allow_redirects=False)
        return response

    def _record(self, response: requests.Response) -> Dict[str, Optional[str]]:
        info = {
            "status": response.status_code,
            "reason": response.reason,
            "content_length": len(response.content),
            "headers": dict(response.headers),
        }
        # Try to parse JSON body, fallback to text snippet
        try:
            info["body"] = response.json()
        except ValueError:
            snippet = response.text.strip()
            info["body"] = snippet[:400] + ("…" if len(snippet) > 400 else "")
        return info

    def _default_browser_headers(self) -> Dict[str, str]:
        return {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
            "Sec-Fetch-Site": "none",
            "Sec-Fetch-Mode": "navigate",
            "Sec-Fetch-Dest": "document",
        }

    # Layer specific methods -------------------------------------------
    def _test_layer_0(self, attempts: int) -> List[Dict[str, Optional[str]]]:
        """Layer0: IP reputation. Requires manual blocklist entry before running."""
        print("[Layer0] Lưu ý: cần thêm IP hiện tại vào blocklist trước khi chạy.")
        headers = self._default_browser_headers()
        responses = []
        for _ in range(attempts):
            resp = self._send_request(headers=headers)
            responses.append(self._record(resp))
            time.sleep(0.2)
        return responses

    def _test_layer_1(self, attempts: int) -> List[Dict[str, Optional[str]]]:
        """Layer1: gửi request thiếu header hoặc UA đáng ngờ."""
        headers = {
            "User-Agent": "curl/7.68.0",
            "Accept": "*/*",
        }
        responses = []
        for _ in range(attempts):
            resp = self._send_request(headers=headers)
            responses.append(self._record(resp))
            time.sleep(0.2)
        return responses

    def _test_layer_2(self, attempts: int) -> List[Dict[str, Optional[str]]]:
        """Layer2: spam nhanh với header hợp lệ."""
        headers = self._default_browser_headers()
        responses = []
        for _ in range(attempts):
            resp = self._send_request(headers=headers)
            responses.append(self._record(resp))
            time.sleep(0.1)
        return responses

    def _test_layer_3(self, attempts: int) -> List[Dict[str, Optional[str]]]:
        """Layer3: mô phỏng trình duyệt không hoàn thành challenge."""
        headers = self._default_browser_headers()
        responses = []
        for _ in range(attempts):
            # Không chạy JS => luôn nhận challenge
            resp = self._send_request(headers=headers)
            responses.append(self._record(resp))
            time.sleep(0.2)
        return responses

    def _test_layer_4(self, attempts: int) -> List[Dict[str, Optional[str]]]:
        """Layer4: giảm điểm hành vi bằng cách gửi request thiếu header kết hợp spam nhanh."""
        responses = []
        for i in range(attempts):
            headers = self._default_browser_headers()
            if i % 2 == 0:
                headers.pop("Sec-Fetch-Site", None)
                headers.pop("Sec-Fetch-Mode", None)
            resp = self._send_request(headers=headers)
            responses.append(self._record(resp))
            time.sleep(0.1)
        return responses

    def _test_layer_5(self, attempts: int) -> List[Dict[str, Optional[str]]]:
        """Layer5: refresh/tabs dày đặc."""
        headers = self._default_browser_headers()
        responses = []
        for _ in range(attempts):
            resp = self._send_request(headers=headers)
            responses.append(self._record(resp))
            time.sleep(0.05)
        return responses


def choose_layer() -> str:
    print("Chọn layer cần test:")
    for key, desc in LAYERS.items():
        print(f"  {key}: {desc}")
    while True:
        choice = input("Nhập layer (0-5): ").strip()
        if choice in LAYERS:
            return choice
        print("Layer không hợp lệ, vui lòng nhập lại (0-5).")


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="Tester cho từng layer anti-DDoS")
    parser.add_argument("--layer", help="Layer cần test (0-5). Nếu bỏ trống sẽ hỏi sau.")
    parser.add_argument("--host", default=DEFAULT_HOST, help="Host cần test (mặc định http://localhost/)")
    parser.add_argument("--attempts", type=int, default=5, help="Số lần gửi request (mặc định 5)")
    args = parser.parse_args(argv)

    layer = args.layer if args.layer in LAYERS else choose_layer()
    scenario = LayerScenario(LAYERS[layer], args.host)

    print(f"\nĐang test {LAYERS[layer]} trên {args.host} ({args.attempts} lần)...")
    try:
        responses = scenario.run(layer, attempts=args.attempts)
    except Exception as exc:  # pylint: disable=broad-except
        print(f"Lỗi khi chạy scenario: {exc}")
        return 1

    print("\nKết quả:")
    for idx, result in enumerate(responses, start=1):
        print(f"--- Request #{idx} ---")
        print(json.dumps(result, indent=2, ensure_ascii=False))

    print("\nHoàn tất. Kiểm tra security/logs/security.log để xem log chi tiết.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
