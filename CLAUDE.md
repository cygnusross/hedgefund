# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Development
- `composer run dev` - Start development server with all services (Laravel server, queue worker, log monitoring via Pail, Vite asset compilation)
- `php artisan serve` - Laravel development server only
- `npm run dev` - Vite asset compilation in development mode
- `npm run build` - Build assets for production

### Testing
- `php artisan test` - Run all tests (uses Pest)
- `php artisan test tests/Feature/ExampleTest.php` - Run specific test file
- `php artisan test --filter=testName` - Filter tests by name
- `vendor/bin/pint --dirty` - Format code according to project standards

### Trading System Commands
- `php artisan decision:preview EUR/USD` - Preview trading decision for currency pair
- `php artisan decision:preview EUR/USD --force-sentiment --strict` - Force refresh sentiment data and use strict mode
- `php artisan context:preview EUR/USD` - Show full market context data
- `php artisan context:preview EUR/USD --now="2025-09-14 14:30:00"` - Historical context preview
- `php artisan candles:refresh EUR/USD --days=30` - Refresh price data
- `php artisan calendar:import-csv /path/to/events.csv` - Import economic calendar events
- `php artisan rules:reload` - Reload trading rules configuration
- `php artisan rules:activate` - Activate rule sets
- `php artisan rules:calibrate` - Calibrate rule parameters

### Database
- `php artisan migrate` - Run migrations
- `php artisan db:seed` - Seed database
- `php artisan migrate --seed` - Migrate and seed

## Architecture Overview

This is a **quantitative forex trading platform** built with Laravel using Domain-Driven Design principles. The system analyzes market data, calculates technical indicators, monitors economic events, and makes automated trading decisions based on configurable rule sets.

### Core Domain Structure

```
app/Domain/
├── Decision/        # Core trading decision engine and DTOs
│   ├── LiveDecisionEngine.php     # Main decision logic
│   ├── DecisionContext.php        # Aggregated market context
│   ├── FeatureExtractor.php       # Technical analysis calculations
│   └── DTO/                       # Data transfer objects for decisions
├── Rules/           # Trading rules system with dynamic configuration
│   ├── AlphaRules.php             # Base rules engine
│   ├── RuleResolver.php           # Rule resolution logic
│   ├── RuleContextManager.php     # Context-aware rule management
│   └── Calibration/               # Rule parameter optimization
├── Features/        # Technical indicator calculations
│   └── FeatureEngine.php          # EMA, ATR, ADX, support/resistance
├── Risk/           # Position sizing and risk management
│   └── MonteCarloSimulator.php   # Risk simulation
└── FX/             # Forex-specific calculations (spreads, pips)
```

### Application Layer

```
app/Application/
├── ContextBuilder.php         # Aggregates all data for decision making
├── Candles/                   # Price data management
└── Calendar/                  # Economic calendar integration
```

### Key Components

#### 1. Decision Engine (`LiveDecisionEngine`)
- **Input**: Market context (prices, features, calendar, sentiment)
- **Processing**: Applies layered rules from RuleContextManager or fallback AlphaRules
- **Gates**: Market status, data freshness, calendar blackouts, spread requirements, daily stops
- **Output**: Trading decision (buy/sell/hold) with confidence and reasoning

#### 2. Context Builder (`ContextBuilder`)
Aggregates data from multiple sources:
- Market data (prices, spreads, trading status)
- Technical features (EMA, ATR, ADX, trend analysis)
- Economic calendar events and blackout periods
- Sentiment data and position information

#### 3. Rules System (New Dynamic Architecture)
- `RuleContextManager` - Manages multiple rule sets with regime-based switching
- `RuleSetRepository` - Handles rule set persistence and versioning
- `RuleResolver` - Resolves rules based on market conditions
- Rule sets stored in database with feature snapshots for calibration
- YAML configuration in `storage/app/alpha_rules.yaml` as fallback

#### 4. Feature Engine (`FeatureEngine`)
Calculates technical indicators:
- EMA (Exponential Moving Average) - trend following
- ATR (Average True Range) - volatility measurement
- ADX (Average Directional Index) - trend strength
- EMA-Z Score - price deviation from mean
- Support/Resistance levels
- 30-minute trend classification

### Data Models

#### Core Tables
- `markets` - Trading instruments (EUR/USD, GBP/USD, etc.)
- `orders` - Trade execution records
- `calendar_events` - Economic events with impact ratings
- `rule_sets` - Dynamic rule configurations
- `rule_set_regimes` - Rule set regime definitions
- `rule_set_feature_snapshots` - Historical feature data for calibration

### Configuration

#### Trading Rules
Rules are managed through a layered system:
1. **Database Rule Sets** (primary) - Dynamic, versioned configurations
2. **YAML Fallback** (`storage/app/alpha_rules.yaml`) - Base configuration

#### Service Configuration
- `config/pricing.php` - Price data provider settings
- `config/decision.php` - Decision engine parameters
- `config/economic.php` - Economic calendar settings

### Testing Structure

Uses **Pest** testing framework:
- `tests/Unit/` - Domain logic (decision engine, feature calculations)
- `tests/Feature/` - Application workflows (context building, CLI commands)
- Key test patterns: DecisionEngine*Test.php, ContextBuilder*Test.php, Rules/*Test.php

### External Integrations

- **TwelveData** - Historical and real-time price data
- **IG Markets** - Live spreads, sentiment data, trade execution
- **Economic Calendar APIs** - High-impact event schedules

### Development Notes

- Uses **Livewire Volt** for interactive components
- **Domain-Driven Design** with strict separation of concerns
- **CQRS patterns** for complex decision-making workflows
- **Repository pattern** for rule set management
- All changes must include tests - run relevant tests after modifications
- Code formatting via `vendor/bin/pint --dirty` before commits
- The system includes extensive safety features: daily loss limits, position sizing, cooldown periods, data freshness checks, and calendar blackouts