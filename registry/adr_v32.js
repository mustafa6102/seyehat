module.exports = [
  {
    "id": "ADR-011",
    "title": "M1A progressive search delivery modeli",
    "status": "PROPOSED",
    "context": "NFR-PERF-001 progressive results ister; polling veya SSE seçimi girdilerde karara bağlanmamıştır.",
    "decision": "OPEN",
    "alternatives": [
      "Asenkron iş + polling",
      "SSE"
    ],
    "consequences": "Seçim yapılana kadar OpenAPI search operasyonları PROVISIONAL."
  }
];
