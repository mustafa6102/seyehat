# Module boundaries

Bu dizin §11.1 bileşen görünümünü birebir modül sınırlarına dönüştürür:

- `Api`: API / Application Layer
- `ProviderAcl`: Provider ACL / Adapters
- `CandidateGenerator`: Candidate Generator
- `FeasibilityCompliance`: Feasibility & Compliance Engine
- `CostRanking`: Cost & Ranking Engine
- `TripBuilder`: Trip Builder
- `AiReasoning`: AI Reasoning Service
- `Audit`: Audit Engine
- `Renderer`: Renderer
- `LiveEventIngestion`: Live Event Ingestion worker
- `Replan`: Replan Engine
- `Notification`: Notification Service

Her modül gerektiğinde `Contracts`, `Application`, `Domain` ve `Infrastructure` namespace'lerini kullanır. Başka bir modül yalnız `Contracts` altındaki public servis arayüzlerine bağımlanabilir. `Infrastructure` sınıfları, provider SDK tipleri ve ham payload'lar modül sınırını aşamaz.

Bu aşamada business story uygulanmadığı için modül dizinleri yalnız sınır işaretleyicileridir.
