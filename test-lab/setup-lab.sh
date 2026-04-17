#!/bin/bash

# Configuration
LAB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MASTER_PORT=6381
REPLICA_PORT=6382
SENTINEL_1_PORT=26381
SENTINEL_2_PORT=26382
MASTER_NAME="mymaster"

set -e

stop_lab() {
    echo "Stopping Redis services..."
    pkill -f "$LAB_DIR/master.conf" || true
    pkill -f "$LAB_DIR/replica.conf" || true
    pkill -f "$LAB_DIR/sentinel_1.conf" || true
    pkill -f "$LAB_DIR/sentinel_2.conf" || true
    
    echo "Waiting for processes to exit..."
    count=0
    while pgrep -f "$LAB_DIR/.*.conf" > /dev/null && [ $count -lt 10 ]; do
        sleep 1
        count=$((count + 1))
    done
    echo "Lab stopped."
}

# Trap errors to cleanup
trap 'echo "Startup failed, cleaning up..."; stop_lab' ERR

wait_for_port() {
    local port=$1
    local name=$2
    local timeout=10
    local count=0
    echo "Waiting for $name on port $port..."
    while ! ss -tln | grep -qE ":$port\b" && [ $count -lt $timeout ]; do
        sleep 1
        count=$((count + 1))
    done
    if [ $count -eq $timeout ]; then
        echo "Error: $name failed to start on port $port"
        return 1
    fi
    return 0
}

start_lab() {
    # Create config directory
    mkdir -p "$LAB_DIR/data"

    cat <<EOF > "$LAB_DIR/master.conf"
port $MASTER_PORT
dir "$LAB_DIR/data"
dbfilename master.rdb
EOF

    cat <<EOF > "$LAB_DIR/replica.conf"
port $REPLICA_PORT
dir "$LAB_DIR/data"
dbfilename replica.rdb
replicaof 127.0.0.1 $MASTER_PORT
EOF

    cat <<EOF > "$LAB_DIR/sentinel_1.conf"
port $SENTINEL_1_PORT
dir "$LAB_DIR/data"
sentinel monitor $MASTER_NAME 127.0.0.1 $MASTER_PORT 2
sentinel down-after-milliseconds $MASTER_NAME 5000
sentinel failover-timeout $MASTER_NAME 60000
EOF

    cat <<EOF > "$LAB_DIR/sentinel_2.conf"
port $SENTINEL_2_PORT
dir "$LAB_DIR/data"
sentinel monitor $MASTER_NAME 127.0.0.1 $MASTER_PORT 2
sentinel down-after-milliseconds $MASTER_NAME 5000
sentinel failover-timeout $MASTER_NAME 60000
EOF

    echo "Starting Redis Master on port $MASTER_PORT..."
    redis-server "$LAB_DIR/master.conf" --daemonize yes
    wait_for_port $MASTER_PORT "Redis Master"
    
    echo "Starting Redis Replica on port $REPLICA_PORT..."
    redis-server "$LAB_DIR/replica.conf" --daemonize yes
    wait_for_port $REPLICA_PORT "Redis Replica"
    
    echo "Starting Redis Sentinel 1 on port $SENTINEL_1_PORT..."
    redis-sentinel "$LAB_DIR/sentinel_1.conf" --daemonize yes
    wait_for_port $SENTINEL_1_PORT "Redis Sentinel 1"

    echo "Starting Redis Sentinel 2 on port $SENTINEL_2_PORT..."
    redis-sentinel "$LAB_DIR/sentinel_2.conf" --daemonize yes
    wait_for_port $SENTINEL_2_PORT "Redis Sentinel 2"
    
    echo "Lab started successfully!"
    echo "Sentinel Nodes:"
    echo "  127.0.0.1:$SENTINEL_1_PORT"
    echo "  127.0.0.1:$SENTINEL_2_PORT"
    echo "Master Name: $MASTER_NAME"
}

status_lab() {
    echo "Checking ports..."
    # Anchored ports to avoid partial matches, removed -p to avoid sudo requirement
    # Use || true to avoid script exit if no ports match
    ss -tln | grep -E ":($MASTER_PORT|$REPLICA_PORT|$SENTINEL_1_PORT|$SENTINEL_2_PORT)\b" || echo "No lab services running."
}


case "$1" in
    start)
        start_lab
        ;;
    stop)
        stop_lab
        ;;
    status)
        status_lab
        ;;
    *)
        echo "Usage: $0 {start|stop|status}"
        exit 1
esac
