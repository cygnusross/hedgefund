# Hedge Fund Trading System

A Laravel-based algorithmic trading system for foreign exchange (FX) markets. This application provides automated decision-making capabilities for forex trading using technical analysis, news sentiment, and economic calendar data.

## 🎯 System Overview

This is a **quantitative trading platform** that:

-   **Analyzes market data** from multiple timeframes (5-minute and 30-minute candles)
-   **Processes news sentiment** to gauge market direction and strength
-   **Monitors economic calendar** events to avoid trading during high-impact announcements
-   **Calculates technical indicators** (EMA, ATR, ADX, support/resistance levels)
-   **Makes trading decisions** based on configurable rule sets
-   **Manages risk** through position sizing, daily stop-losses, and cooldown periods
-   **Estimates spreads** for accurate cost calculations
-   **Provides CLI tools** for previewing decisions and contexts

## 📋 Requirements

-   **PHP**: 8.4+
-   **Laravel**: 12.x
-   **Database**: PostgreSQL (SQLite for development)
-   **Node.js**: For frontend asset compilation
-   **External APIs**:
    -   TwelveData (price data)
    -   ForexNewsAPI (news sentiment)
    -   IG Markets API (spreads and execution)

## 🏗️ Architecture

### Domain-Driven Design Structure

```
app/
├── Application/           # Application services and use cases
│   ├── Calendar/         # Economic calendar management
│   ├── Candles/         # Price data synchronization
│   ├── ContextBuilder   # Aggregates all data for decision making
│   └── News/            # News aggregation and processing
├── Domain/              # Core business logic
│   ├── Decision/        # Trading decision engine
│   ├── Execution/       # Position management contracts
│   ├── Features/        # Technical analysis engine
│   ├── FX/              # Forex-specific calculations
│   ├── Market/          # Market data structures
│   ├── Risk/            # Risk management
│   └── Rules/           # Trading rules configuration
├── Infrastructure/      # External service integrations
│   └── Prices/         # Price data providers
├── Services/           # External API clients
│   ├── Economic/       # Economic calendar APIs
│   ├── IG/            # IG Markets integration
│   ├── News/          # News provider APIs
│   └── Prices/        # Price data APIs
└── Models/            # Eloquent models
```

### Core Components

#### 1. Decision Engine (`DecisionEngine`)

The heart of the system that evaluates trading opportunities:

-   **Input**: Market context (prices, features, news, calendar)
-   **Processing**: Applies configurable gates and rules
-   **Output**: Trading decision (buy/sell/hold) with confidence and reasoning

**Key Gates:**

-   Market status validation (TRADEABLE required)
-   Data freshness checks (max age limits)
-   Calendar blackout periods (avoid high-impact events)
-   Technical volatility filters (ADX minimum, EMA-Z stretch limits)
-   Spread requirements (cost control)
-   Daily loss stops and cooldown periods

#### 2. Context Builder (`ContextBuilder`)

Aggregates all necessary data for decision making:

-   **Market Data**: Current prices, spreads, trading status
-   **Technical Features**: EMA, ATR, ADX, trend analysis, support/resistance
-   **News Sentiment**: Strength and directional bias from news analysis
-   **Economic Calendar**: Upcoming high-impact events and blackout periods
-   **Position Information**: Current exposure and recent trade outcomes

#### 3. Feature Engine (`FeatureEngine`)

Calculates technical indicators from price data:

-   **EMA (Exponential Moving Average)**: Trend following
-   **ATR (Average True Range)**: Volatility measurement
-   **ADX (Average Directional Index)**: Trend strength
-   **EMA-Z Score**: Price deviation from mean
-   **Support/Resistance**: Key price levels
-   **Trend Classification**: 30-minute trend direction

#### 4. News Processing

Analyzes market sentiment from news sources:

-   **Sentiment Aggregation**: Positive/negative/neutral news counts
-   **Direction Bias**: Buy/sell/neutral market direction
-   **Strength Scoring**: 0-1 scale of news impact

#### 5. Calendar Management

Economic event monitoring and blackout logic:

-   **Event Import**: CSV import from multiple formats
-   **Impact Classification**: High/Medium/Low event importance
-   **Blackout Periods**: Avoid trading around high-impact events
-   **Multi-currency Support**: Currency-specific event filtering

## 📊 Data Models

### Core Tables

-   **`markets`**: Trading instruments (EUR/USD, GBP/USD, etc.)
-   **`orders`**: Trade execution records
-   **`calendar_events`**: Economic calendar events with impact ratings
-   **`news_stats`**: News sentiment aggregation by currency pair

### Key Fields

```php
// Market Context Structure
[
    'meta' => [
        'pair_norm' => 'EURUSD',
        'data_age_sec' => 10,
        'sleeve_balance' => 10000.0
    ],
    'market' => [
        'status' => 'TRADEABLE',
        'last_price' => 1.1000,
        'atr5m_pips' => 10,
        'spread_estimate_pips' => 0.5,
        'sentiment' => ['long_pct' => 60.0, 'short_pct' => 40.0]
    ],
    'features' => [
        'ema20' => 1.0950,
        'ema20_z' => 0.5,
        'adx5m' => 25.0,
        'trend30m' => 'up'
    ],
    'news' => [
        'strength' => 0.35,
        'direction' => 'buy'
    ],
    'calendar' => [
        'within_blackout' => false
    ]
]
```

## 🔧 Configuration

### Trading Rules (`config/rules.yaml`)

The system uses YAML configuration for trading rules:

```yaml
gates:
    market_required_status: ["TRADEABLE"]
    max_data_age_sec: 600
    spread_required: true
    adx_min: 20
    z_abs_max: 1.0
    daily_loss_stop_pct: 3.0

confluence:
    news_threshold:
        moderate: 0.3
        strong: 0.45

risk:
    per_trade_pct:
        default: 1.0
        medium_strong: 1.5

execution:
    sl_atr_mult: 2.0 # Stop loss = 2x ATR
    tp_atr_mult: 4.0 # Take profit = 4x ATR

cooldowns:
    after_loss_minutes: 20
    after_win_minutes: 5
```

### Service Configuration

-   **`config/pricing.php`**: Price data provider settings
-   **`config/news.php`**: News API configuration
-   **`config/decision.php`**: Decision engine parameters

## 🚀 Getting Started

### Installation

```bash
# Clone repository
git clone <repository-url>
cd hedgefund

# Install dependencies
composer install
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database setup
php artisan migrate
php artisan db:seed

# Build assets
npm run build
```

### Configuration

Set up your `.env` file with:

```env
# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hedgefund
DB_USERNAME=your_user
DB_PASSWORD=your_password

# Price Data
PRICE_DRIVER=twelvedata
TWELVEDATA_API_KEY=your_api_key

# News Data
NEWS_PROVIDER=forexnewsapi
FOREXNEWSAPI_TOKEN=your_token

# IG Markets (for spreads and execution)
IG_API_KEY=your_api_key
IG_IDENTIFIER=your_username
IG_PASSWORD=your_password
```

### Development

```bash
# Start development server with all services
composer run dev

# This runs concurrently:
# - Laravel development server
# - Queue worker
# - Log monitoring (Pail)
# - Vite asset compilation
```

## 🎮 Usage

### Command Line Interface

#### Preview Trading Decisions

```bash
# Get decision for EUR/USD
php artisan decision:preview EUR/USD

# Force refresh all data sources
php artisan decision:preview EUR/USD --force-news --force-calendar --force-sentiment

# Strict mode (non-zero exit on hold)
php artisan decision:preview EUR/USD --strict
```

#### Preview Market Context

```bash
# Show full context data
php artisan context:preview EUR/USD

# Historical context (specific time)
php artisan context:preview EUR/USD --now="2025-09-14 14:30:00"

# Force data refresh
php artisan context:preview EUR/USD --force-news --force-calendar
```

#### Data Management

```bash
# Refresh price data
php artisan candles:refresh EUR/USD --days=30

# Import economic calendar
php artisan calendar:import-csv /path/to/events.csv

# Refresh news sentiment
php artisan news:refresh EUR/USD

# Reload trading rules
php artisan rules:reload
```

### Web Interface

The application includes a Livewire-powered web interface with:

-   **Dashboard**: System status and recent decisions
-   **Settings**: User preferences and appearance
-   **Health Endpoints**: `/candles/health/{pair}` for monitoring

### API Integration

The system integrates with multiple external APIs:

1. **TwelveData**: Historical and real-time price data
2. **ForexNewsAPI**: News sentiment analysis
3. **IG Markets**: Live spreads and trade execution
4. **Economic Calendar**: High-impact event schedules

## 🧪 Testing

The project uses **Pest** for testing with comprehensive coverage:

```bash
# Run all tests
php artisan test

# Run specific test suites
php artisan test --filter=DecisionEngine
php artisan test --filter=CalendarCsv
php artisan test --filter=ContextBuilder

# Test with coverage
php artisan test --coverage
```

### Test Structure

-   **Unit Tests**: Domain logic (decision engine, feature calculation)
-   **Feature Tests**: Application workflows (context building, data import)
-   **Integration Tests**: External API interactions

Key test files:

-   `DecisionEngine*Test.php`: Trading logic validation
-   `ContextBuilder*Test.php`: Data aggregation testing
-   `CalendarCsv*Test.php`: Calendar import functionality

## 🔒 Risk Management

### Built-in Safety Features

1. **Daily Loss Limits**: Configurable percentage-based stops
2. **Position Sizing**: ATR-based risk calculation
3. **Cooldown Periods**: Post-trade waiting periods
4. **Data Freshness**: Reject stale market data
5. **Spread Monitoring**: Cost-aware execution
6. **Calendar Blackouts**: Avoid high-volatility periods

### Example Risk Configuration

```yaml
risk:
    per_trade_pct:
        default: 1.0 # 1% risk per trade
        high_confidence: 1.5

gates:
    daily_loss_stop_pct: 3.0 # Stop trading at -3% daily
    max_positions: 3
    max_pair_exposure_pct: 20.0

cooldowns:
    after_loss_minutes: 30
    after_win_minutes: 10
```

## 🔧 Customization

### Adding New Indicators

1. Extend `FeatureEngine` with new calculations
2. Update `FeatureSet` class structure
3. Add indicator logic to decision rules
4. Write comprehensive tests

### Custom News Providers

1. Implement `NewsProvider` interface
2. Register in `AppServiceProvider`
3. Configure in `config/news.php`
4. Add provider-specific tests

### Rule Modifications

Edit the YAML rules configuration to adjust:

-   Entry/exit criteria
-   Risk parameters
-   Gate conditions
-   Confluence requirements

## 📈 Monitoring

### Health Checks

```bash
# Check candle data health
curl http://localhost:8000/candles/health/EUR/USD

# Monitor logs in real-time
php artisan pail

# Check queue status
php artisan queue:monitor
```

### Logging

The system logs extensively for debugging and analysis:

-   Decision reasoning and blocked trades
-   Data refresh operations
-   External API interactions
-   Error conditions and fallbacks

## 🤝 Contributing

1. **Fork** the repository
2. **Create** a feature branch (`git checkout -b feature/amazing-feature`)
3. **Test** your changes (`php artisan test`)
4. **Format** code (`vendor/bin/pint`)
5. **Commit** changes (`git commit -m 'Add amazing feature'`)
6. **Push** to branch (`git push origin feature/amazing-feature`)
7. **Create** a Pull Request

### Development Guidelines

-   Follow Laravel conventions and patterns
-   Write comprehensive tests for new features
-   Use type hints and return types
-   Document complex business logic
-   Run Pint for code formatting
-   Maintain backward compatibility

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙋‍♂️ Support

For questions and support:

1. Check the [tests/](tests/) directory for usage examples
2. Review [config/](config/) files for configuration options
3. Examine [app/Console/Commands/](app/Console/Commands/) for CLI usage
4. Read the source code - it's well-documented!

## 🔮 Future Enhancements

Potential areas for expansion:

-   Machine learning integration for prediction models
-   Multi-broker execution support
-   Advanced portfolio optimization
-   Real-time streaming data integration
-   Mobile application development
-   Advanced backtesting capabilities

---

**⚠️ Disclaimer**: This software is for educational and research purposes. Trading foreign exchange involves substantial risk and may not be suitable for all investors. Past performance does not guarantee future results.
