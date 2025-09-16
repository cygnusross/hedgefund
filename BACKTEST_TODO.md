# Backtesting MVP Implementation TODO

## 📋 Current Status Assessment

✅ **Historical Data Foundation Complete**

-   Candle data: 3 years of 5min/30min OHLC (market hours only)
-   Economic calendar: High-impact events stored
-   News sentiment: Historical data available
-   Spread history: Collection system active (going forward)

✅ **Core Trading Logic Complete**

-   DecisionEngine: Gates, rules, sizing, safety checks
-   FeatureEngine: EMA, ATR, ADX, support/resistance
-   ContextBuilder: Aggregates market data, features, news

❌ **Missing: Historical Data Integration**

---

## 🚨 **API Separation Strategy**

**Problem**: Current `ContextBuilder` dependencies hit live APIs that would be problematic for thousands of backtesting simulations:

### ❌ **Live API Dependencies (Must Replace):**

-   **CandleUpdater** → TwelveData API (rate limits, costs)
-   **SpreadEstimator** → IG API for current spreads (live only)
-   **ClientSentimentProvider** → IG API for current sentiment (live only)

### ✅ **Database-Backed (Safe for Backtesting):**

-   **NewsAggregator** → Queries `NewsStat` model first, API fallback
-   **CalendarLookup** → Queries `CalendarEvent` model (historical data)

### 🔄 **Hybrid (Store + API Fallback Pattern):**

-   **ClientSentimentProvider** → Should store hourly snapshots to `client_sentiment` table (like spreads)
-   **SpreadEstimator** → Already stores hourly snapshots to `spreads` table via `spreads:record-current` command

### 💾 **Storage Strategy:**

-   **Spreads**: `spreads:record-current` runs hourly during trading hours (07:00-22:00 London)
-   **Client Sentiment**: Need `sentiment:record-current` with same schedule pattern
-   **Live Trading**: Use live APIs for real-time decisions
-   **Backtesting**: Query stored snapshots for consistent historical data

### 🎭 **Data Availability & Mocking Strategy:**

-   **Historical Data Available**: Use actual stored spreads/sentiment from database
-   **Historical Data Missing**: Generate deterministic mock data for consistent backtesting
-   **Mock Spread Strategy**: Use `PipMath::spreadEstimatePips()` conservative defaults (EUR/USD: 0.8 pips)
-   **Mock Sentiment Strategy**: Generate neutral sentiment (50/50 long/short) or pair-specific patterns
-   **Consistency**: Same mock data for same timestamp ensures reproducible backtest results

### 🔄 **Backtesting Isolation Strategy:**

-   **Mock Trades**: Store hypothetical orders/positions in Redis cache with backtest session ID
-   **Cache Keys**: `backtest:{session_id}:positions`, `backtest:{session_id}:orders`
-   **No DB Pollution**: Zero impact on production `orders` and position tracking tables
-   **Results Storage**: Dedicated `backtest_results` table for performance analysis
-   **Session Management**: Each backtest gets unique ID for complete isolation
-   **Cleanup**: Automatic Redis cleanup after backtest completion

### 🎯 **Solution:**

-   **Live Trading**: Use existing IG API providers for real-time data
-   **Backtesting**: Use historical database providers for consistent simulation
-   **Same Logic**: `DecisionEngine`, `FeatureEngine`, rules unchanged

---

## 🎯 Phase 1: Core Backtesting Infrastructure (MVP)

### 1.1 Historical Data Providers (CRITICAL)

**Priority: CRITICAL**

-   [ ] Create `HistoricalCandleUpdater` implementing `CandleUpdaterContract`
-   [ ] Create `HistoricalSpreadEstimator` implementing `SpreadEstimator` interface
-   [ ] Create `MockClientSentimentProvider` for consistent backtest sentiment
-   [ ] Query database instead of hitting live APIs (TwelveData, IG)
-   [ ] **Data Fallbacks**: When historical data missing, use deterministic mocks
-   [ ] **Spread Fallback**: Use `PipMath::spreadEstimatePips()` conservative defaults
-   [ ] **Sentiment Fallback**: Generate neutral 50/50 or deterministic patterns
-   [ ] **Reuse**: `NewsAggregator` already DB-first, `CalendarLookup` already DB-backed
-   [ ] **Files**:
    -   `app/Application/Candles/HistoricalCandleUpdater.php`
    -   `app/Domain/FX/HistoricalSpreadEstimator.php`
    -   `app/Services/IG/MockClientSentimentProvider.php`
-   [ ] **Tests**: Unit tests for each historical provider

### 1.2 Backtest Command Foundation

**Priority: HIGH**

-   [ ] Create `trading:backtest` Artisan command
-   [ ] Add parameters: `{pair} {--from=} {--to=} {--interval=5min}`
-   [ ] Inject historical providers into existing `ContextBuilder`
-   [ ] **API Separation**: Use historical spreads for backtest, live IG API for production
-   [ ] **Files**: `app/Console/Commands/TradingBacktest.php`
-   [ ] **Tests**: `tests/Feature/Console/TradingBacktestTest.php`### 1.3 Historical Decision Execution

**Priority: HIGH**

-   [ ] Loop through date range, building context at each timestamp
-   [ ] **Reuse**: Existing `ContextBuilder->build()` method unchanged
-   [ ] **Reuse**: Existing `DecisionEngine->decide()` method unchanged
-   [ ] **API Safety**: No live API calls during backtesting (spreads from DB, sentiment mocked)
-   [ ] **Position Tracking**: Mock trades/orders in Redis cache (no DB pollution)
-   [ ] **Results Storage**: Separate `backtest_results` table for analysis
-   [ ] Track hypothetical positions and P&L
-   [ ] **Integration**: Zero changes to core trading logic

---

## 🎯 Phase 2: Essential Backtesting Features

### 2.1 Position & P&L Tracking

**Priority: MEDIUM**

-   [ ] Create `BacktestPosition` value object
-   [ ] Create `BacktestPositionLedger` using Redis for session isolation
-   [ ] Implement position sizing using existing logic
-   [ ] Calculate P&L with spread costs
-   [ ] Track drawdown and win/loss ratios
-   [ ] **Files**: `app/Domain/Backtesting/BacktestPosition.php`, `app/Domain/Backtesting/BacktestPositionLedger.php`

### 2.2 Backtest Results Storage

**Priority: MEDIUM**

-   [ ] Create `backtest_results` table migration
-   [ ] Create `BacktestResult` model for storing completed backtest summaries
-   [ ] Store: session_id, pair, date_range, total_return, win_rate, max_drawdown, trade_count
-   [ ] **Files**: `database/migrations/*_create_backtest_results_table.php`, `app/Models/BacktestResult.php`

### 2.3 Basic Reporting

**Priority: MEDIUM**

-   [ ] Generate summary statistics (total return, Sharpe ratio, max drawdown)
-   [ ] Show key trades and decision points
-   [ ] Display performance by time period
-   [ ] Export results to JSON/CSV
-   [ ] **Files**: `app/Domain/Backtesting/BacktestReport.php`

### 2.4 Enhanced Preview Commands

**Priority: LOW**

-   [ ] Add `--date` parameter to `context:preview`
-   [ ] Add `--date` parameter to `decision:preview`
-   [ ] Enable historical context inspection
-   [ ] **Modification**: Update existing preview commands

---

## 🎯 Phase 3: Performance & Polish

### 3.1 Performance Optimization

**Priority: LOW**

-   [ ] Add database indexes for backtest queries
-   [ ] Implement chunk processing for large date ranges
-   [ ] Add caching for repeated calculations
-   [ ] Memory optimization for long backtests

### 3.2 Validation & Error Handling

**Priority: LOW**

-   [ ] Validate sufficient historical data exists
-   [ ] Handle missing data gracefully (holidays, weekends)
-   [ ] Add comprehensive error messages
-   [ ] Implement resume functionality for interrupted backtests

---

## � **Iterative Strategy Testing Workflow**

### **Rapid Rule Testing Cycle:**

```bash
# 1. Run backtest with current rules
php artisan trading:backtest USD/JPY --from="2023-01-01" --to="2023-12-31"
# → Processes hundreds of decisions in Redis cache
# → Records final results to backtest_results table

# 2. Analyze results
php artisan backtest:results --latest
# → Shows: Win rate: 45%, Total return: -5.2%, Max drawdown: 12%

# 3. Tweak rules (adjust gates, confluence, risk settings)
php artisan rules:reload

# 4. Run again with tweaked rules
php artisan trading:backtest USD/JPY --from="2023-01-01" --to="2023-12-31"
# → New session ID, fresh Redis cache
# → Compare results: Win rate: 67%, Total return: +12.5%, Max drawdown: 8%

# 5. Compare multiple runs
php artisan backtest:compare --sessions="abc123,def456"
```

### **Benefits:**

-   ✅ **Fast Iteration**: Redis cache = thousands of decisions per minute
-   ✅ **Rule Experimentation**: Tweak gates, risk, confluence settings rapidly
-   ✅ **Results Comparison**: Compare different rule configurations side-by-side
-   ✅ **Zero Risk**: No production data pollution, pure historical simulation
-   ✅ **Reproducible**: Same rules + same data = identical results every time

---

## �📊 Implementation Strategy

### Step 1: Proof of Concept (1-2 hours)

```bash
# Create historical candle updater (implements existing contract)
php artisan make:class Application/Candles/HistoricalCandleUpdater

# Create basic backtest command
php artisan make:command TradingBacktest

# Test with small date range (1 day) - reuses ALL existing logic
php artisan trading:backtest EUR/USD --from="2023-08-29" --to="2023-08-30"
```

### Step 2: Core Integration (2-3 hours)

-   Wire historical providers into existing ContextBuilder
-   **Critical**: Verify zero live API calls during backtesting
-   **Spread Strategy**: Use historical spread data from database for backtests
-   **Sentiment Strategy**: Use consistent/mocked sentiment for deterministic results
-   Implement position tracking and basic P&L
-   Test with 1-week backtest

### Step 3: MVP Complete (1-2 hours)

-   Add reporting and summary statistics
-   Comprehensive testing and error handling
-   Documentation and usage examples

---

## 🧪 Testing Strategy

### Essential Tests

-   [ ] Unit tests for `HistoricalCandleUpdater` (implements CandleUpdaterContract)
-   [ ] Unit tests for `HistoricalSpreadEstimator` (no live IG API calls)
-   [ ] Integration tests ensuring ContextBuilder works with all historical providers
-   [ ] Feature tests for backtest command with real historical data
-   [ ] **Critical**: Verify zero external API calls during backtesting simulation
-   [ ] Verify same DecisionEngine results between live and backtest modes (with same data)

### Test Data Requirements

-   Use existing USD/JPY historical data (2022-2023)
-   Mock spread data for consistent P&L calculations
-   Test edge cases: market gaps, low volume periods

---

## 🎯 Success Criteria (MVP)

**Core Functionality:**

-   [ ] Run backtest on historical data without hitting live APIs
-   [ ] Generate buy/sell decisions using existing DecisionEngine logic
-   [ ] Calculate realistic P&L including spread costs
-   [ ] Display basic performance metrics

**Command Usage:**

```bash
# Single backtest run
php artisan trading:backtest USD/JPY --from="2023-01-01" --to="2023-12-31"
# Output: Session: abc123 | Total trades: 45, Win rate: 67%, Total return: +12.5%

# Rule optimization workflow
php artisan rules:reload                    # Load new rule tweaks
php artisan trading:backtest USD/JPY --from="2023-01-01" --to="2023-12-31"
php artisan backtest:results --latest       # Compare with previous runs
```

**Integration Proof:**

-   [ ] Same decision logic as live trading
-   [ ] Historical context matches live context generation
-   [ ] Spreads and costs properly applied
-   [ ] Results are deterministic and repeatable

---

## 📋 File Structure

```
app/
├── Console/Commands/
│   └── TradingBacktest.php
├── Application/Candles/
│   └── HistoricalCandleUpdater.php  # Implements CandleUpdaterContract
├── Domain/Backtesting/
│   ├── BacktestPosition.php
│   ├── BacktestPositionLedger.php   # Redis-based position tracking
│   ├── BacktestReport.php
│   └── BacktestEngine.php
├── Models/
│   └── BacktestResult.php           # Stores completed backtest summaries

database/migrations/
└── *_create_backtest_results_table.php

tests/
├── Feature/Console/
│   └── TradingBacktestTest.php
├── Unit/Application/Candles/
│   └── HistoricalCandleUpdaterTest.php
```

---

## ⚡ Quick Start Commands

```bash
# Generate required files
php artisan make:class Application/Candles/HistoricalCandleUpdater --no-interaction
php artisan make:command TradingBacktest --no-interaction
php artisan make:test Feature/Console/TradingBacktestTest --pest --no-interaction

# Run focused tests during development
php artisan test --filter=TradingBacktest

# Format code
vendor/bin/pint --dirty
```

## 🎯 Key Architecture Insight

**Reuse Existing Services**: The current `ContextBuilder` already uses dependency injection perfectly. We just need to swap out the `CandleUpdaterContract` implementation from live API calls to database queries. This means:

✅ **Zero changes** to `DecisionEngine`, `FeatureEngine`, `NewsAggregator`, `CalendarLookup`  
✅ **Zero changes** to existing trading logic, rules, gates, safety checks  
✅ **Same exact results** between live trading and backtesting  
✅ **Minimal code duplication** - just one new class implementing existing contract

This approach leverages your existing architecture perfectly and avoids duplicating any business logic.
